<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiconPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadministrators_are_excluded_before_pagination_and_from_people_selectors(): void
    {
        $root = User::factory()->create(['role' => 'superadmin', 'active' => true, 'username' => 'root-hidden']);
        User::factory()->create(['role' => 'superadmin', 'active' => true, 'username' => 'root-second', 'name' => 'Hidden Root']);
        $tech = User::factory()->create(['role' => 'technician', 'active' => true, 'username' => 'tecnico-visible']);
        $this->actingAs($root);
        $response = $this->get('/usuarios')->assertOk();
        $this->assertSame(1, $response->viewData('users')->total());
        $this->assertSame([$tech->id], $response->viewData('users')->pluck('id')->all());
        $response->assertDontSee('Hidden Root')->assertDontSee('root-second');
        $this->assertArrayNotHasKey('superadmin', $response->viewData('roles'));
        foreach (['/gastos', '/caja-chica'] as $url) {
            $page = $this->get($url)->assertOk();
            $this->assertSame([$tech->id], $page->viewData('people')->pluck('id')->all());
        }
    }

    public function test_ficon_identity_and_theme_control_replace_removed_navigation_copy(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($user)->get('/configuracion')->assertOk()->assertSee('Gastos Ficon')->assertSee('data-theme-toggle', false)->assertSee('ficon-logo.png')->assertDontSee('Mi espacio de trabajo')->assertDontSee('Un gasto a la vez.')->assertDontSee('Tus cuentas claras, tus decisiones más simples.')->assertDontSee('Espacio privado')->assertDontSee('Cuenta Clara');
    }
}
