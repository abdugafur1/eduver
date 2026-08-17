<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * There is no homepage. Route::redirect('/', '/sets') sends the root
     * straight to the browser, so the stock "returns 200" assertion this
     * replaces had been failing since that line was added.
     */
    public function test_the_root_redirects_to_the_set_browser(): void
    {
        $this->get('/')
            ->assertRedirect(route('sets.index'));
    }
}
