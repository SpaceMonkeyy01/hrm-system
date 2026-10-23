<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Settings > System architecture: docs/ARCHITECTURE.md rendered for admins only. */
class ArchitecturePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_read_the_architecture_doc_and_nobody_else_does(): void
    {
        $this->actingAs($this->user('admin'))->get('/settings/architecture')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('settings/architecture')
            ->where('html', fn ($html) => str_contains($html, '<h2>1. Deployment</h2>') && str_contains($html, 'language-mermaid') && ! str_contains($html, '<script')));

        foreach (['hr', 'viewer', 'employee'] as $role) {
            $this->actingAs($this->user($role))->get('/settings/architecture')->assertForbidden();
        }
    }
}
