<?php

namespace Adldap\Laravel\Tests;

use Adldap\Models\User;
use Adldap\AdldapInterface;
use Adldap\Laravel\Commands\Import;
use Adldap\Laravel\Facades\Resolver;
use Adldap\Laravel\Tests\Scopes\JohnDoeScope;
use Adldap\Laravel\Tests\Models\User as EloquentUser;
use Adldap\Laravel\Tests\Handlers\LdapAttributeHandler;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class DatabaseProviderTest extends DatabaseTestCase
{
    /**
     * @expectedException \Adldap\Laravel\Exceptions\ConfigurationMissingException
     */
    public function test_configuration_not_found_exception()
    {
        config(['adldap' => null]);

        App::make('adldap');
    }

    public function test_registration()
    {
        $this->assertInstanceOf(\Adldap\Laravel\AdldapServiceProvider::class, app()->register(\Adldap\Laravel\AdldapServiceProvider::class));
        $this->assertInstanceOf(\Adldap\Laravel\AdldapAuthServiceProvider::class, app()->register(\Adldap\Laravel\AdldapAuthServiceProvider::class));
    }

    public function test_contract_resolve()
    {
        $adldap = App::make(AdldapInterface::class);

        $this->assertInstanceOf(AdldapInterface::class, $adldap);
    }

    public function test_auth_passes($credentials = null)
    {
        $credentials = $credentials ?: ['email' => 'jdoe@email.com', 'password' => '12345'];

        $user = $this->makeLdapUser([
            'cn'    => 'John Doe',
            'userprincipalname'  => 'jdoe@email.com',
        ]);

        Resolver::shouldReceive('byModel')->once()->andReturn($user)
            ->shouldReceive('byCredentials')->once()->andReturn($user)
            ->shouldReceive('authenticate')->once()->andReturn(true);

        $this->assertTrue(Auth::attempt($credentials));
        $this->assertInstanceOf(EloquentUser::class, Auth::user());
        $this->assertInstanceOf(User::class, Auth::user()->ldap);
    }

    public function test_auth_fails_when_user_found()
    {
        $user = $this->makeLdapUser([
            'cn'    => 'John Doe',
            'userprincipalname'  => 'jdoe@email.com',
        ]);

        Resolver::shouldReceive('byCredentials')->once()->andReturn($user)
            ->shouldReceive('authenticate')->once()->andReturn(false);

        $this->assertFalse(Auth::attempt(['email' => 'jdoe@email.com', 'password' => '12345']));
    }

    public function test_auth_fails_when_user_not_found()
    {
        Resolver::shouldReceive('byCredentials')->once()->andReturn(null);

        $this->assertFalse(Auth::attempt(['email' => 'jdoe@email.com', 'password' => '12345']));
    }

    public function test_config_scopes()
    {
        $scopes = config('adldap_auth.scopes', []);

        $scopes[] = JohnDoeScope::class;

        config(['adldap_auth.scopes' => $scopes]);

        $expectedFilter = '(&(objectclass=\70\65\72\73\6f\6e)(objectcategory=\70\65\72\73\6f\6e)(userprincipalname=*)(cn=\4a\6f\68\6e\20\44\6f\65))';

        $this->assertEquals($expectedFilter, Resolver::query()->getQuery());
    }

    public function test_config_callback_attribute_handler()
    {
        $default = config('adldap_auth.sync_attributes');

        config(['adldap_auth.sync_attributes' => array_merge($default, [LdapAttributeHandler::class])]);

        $this->test_auth_passes();

        $user = Auth::user();

        $this->assertEquals('handled', $user->name);
    }

    /**
     * @expectedException \Adldap\AdldapException
     */
    public function test_config_invalid_attribute_handler()
    {
        // Inserting an invalid attribute handler that
        // does not contain a `handle` method.
        config(['adldap_auth.sync_attributes' => [\stdClass::class]]);

        $user = $this->makeLdapUser([
            'cn'    => 'John Doe',
            'userprincipalname'  => 'jdoe@email.com',
        ]);

        $model = new EloquentUser();

        $importer = new Import($user, $model);

        $importer->handle();
    }

    public function test_config_login_fallback()
    {
        config(['adldap_auth.login_fallback' => true]);

        EloquentUser::create([
            'email'    => 'jdoe@email.com',
            'name'     => 'John Doe',
            'password' => bcrypt('Password123'),
        ]);

        $credentials = [
            'email'    => 'jdoe@email.com',
            'password' => 'Password123',
        ];

        Resolver::shouldReceive('byCredentials')->times(3)->andReturn(null)
            ->shouldReceive('byModel')->once()->andReturn(null);

        $this->assertTrue(Auth::attempt($credentials));

        $this->assertFalse(Auth::attempt(
            array_replace($credentials, ['password' => 'Invalid'])
        ));

        config(['adldap_auth.login_fallback' => false]);

        $this->assertFalse(Auth::attempt($credentials));
    }

    public function test_config_login_fallback_no_connection()
    {
        config(['adldap_auth.login_fallback' => true]);

        EloquentUser::create([
            'email'    => 'jdoe@email.com',
            'name'     => 'John Doe',
            'password' => bcrypt('Password123'),
        ]);

        $credentials = [
            'email'    => 'jdoe@email.com',
            'password' => 'Password123',
        ];

        $this->assertTrue(Auth::attempt($credentials));

        $user = Auth::user();

        $this->assertInstanceOf('Adldap\Laravel\Tests\Models\User', $user);
        $this->assertEquals('jdoe@email.com', $user->email);
    }

    public function test_config_password_sync_enabled()
    {
        config(['adldap_auth.passwords.sync' => true]);

        $credentials = [
            'email' => 'jdoe@email.com',
            'password' => '12345',
        ];

        $this->test_auth_passes($credentials);

        $user = EloquentUser::first();

        // This check will pass due to password synchronization being enabled.
        $this->assertTrue(Hash::check($credentials['password'], $user->password));
    }

    public function test_config_password_sync_disabled()
    {
        config(['adldap_auth.passwords.sync' => false]);

        $credentials = [
            'email' => 'jdoe@email.com',
            'password' => '12345',
        ];

        $this->test_auth_passes($credentials);

        $user = EloquentUser::first();

        // This check will fail due to password synchronization being disabled.
        $this->assertFalse(Hash::check($credentials['password'], $user->password));
    }

    public function test_deny_trashed_rule()
    {
        config([
            'adldap_auth.login_fallback' => false,
            'adldap_auth.rules' => [\Adldap\Laravel\Validation\Rules\DenyTrashed::class],
        ]);

        $credentials = [
            'email' => 'jdoe@email.com',
            'password' => '12345',
        ];

        $ldapUser = $this->makeLdapUser();

        Resolver::shouldReceive('byCredentials')->twice()->andReturn($ldapUser)
            ->shouldReceive('byModel')->once()->andReturn($ldapUser)
            ->shouldReceive('authenticate')->twice()->andReturn(true);

        $this->assertTrue(Auth::attempt($credentials));

        EloquentUser::first()->delete();

        $this->assertFalse(Auth::attempt($credentials));
    }

    public function test_only_imported_rule()
    {
        config([
            'adldap_auth.login_fallback' => false,
            'adldap_auth.rules' => [\Adldap\Laravel\Validation\Rules\OnlyImported::class],
        ]);

        $credentials = [
            'email' => 'jdoe@email.com',
            'password' => '12345',
        ];

        Resolver::shouldReceive('byCredentials')->once()->andReturn($this->makeLdapUser())
            ->shouldReceive('authenticate')->once()->andReturn(true);

        $this->assertFalse(Auth::attempt($credentials));
    }

    public function test_calls_to_fallback_provider()
    {
        $this->assertEquals('Adldap\Laravel\Tests\Models\User', Auth::getProvider()->getModel());
    }
}
