<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocsRouteTest extends TestCase
{
    public function test_docs_page_renders_browseable_api_explorer(): void
    {
        $response = $this->get('/docs/api');

        $response->assertStatus(200);
        $response->assertSee('Wallet API v1', false);
        $response->assertSee('Try it out', false);

        $specResponse = $this->get('/docs/api.json');

        $specResponse->assertStatus(200);
        $specResponse->assertJsonPath('components.securitySchemes.http.scheme', 'bearer');
    }

    public function test_docs_hub_links_to_reference_and_guides(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        foreach (['/docs/api', '/docs/database-schema', '/docs/android-room-schema', '/docs/push-changes', '/docs/changelog'] as $link) {
            $response->assertSee('href="'.$link.'"', false);
        }
    }

    public function test_docs_guides_and_changelog_render(): void
    {
        foreach (['/docs/database-schema', '/docs/android-room-schema', '/docs/push-changes', '/docs/changelog'] as $uri) {
            $this->get($uri)->assertStatus(200);
        }
    }

    public function test_removed_api_v1_page_is_gone(): void
    {
        $this->get('/docs/api-v1')->assertStatus(404);
    }
}
