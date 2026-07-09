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
}
