<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

/** EA-5 (A-03): a field with an error says so and points at its message. */
class AccessibleFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_profile_field_with_an_error_is_marked_and_described_by_its_message(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['name' => '', 'email' => 'someone@example.test'])
            ->call('updateProfileInformation')
            ->assertHasErrors(['name'])
            ->assertSeeHtml('aria-invalid="true" aria-describedby="error-name"')
            ->assertSeeHtml('id="error-name"');
    }

    public function test_a_field_without_an_error_carries_no_error_markup(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)->assertDontSeeHtml('aria-invalid');
    }

    public function test_the_new_workbook_form_ties_its_errors_to_fields(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create());

        $this->followingRedirects()->from(route('spreadsheets.create'))->post(route('spreadsheets.store'), ['name' => ''])
            ->assertSee('aria-describedby="error-name"', false)
            ->assertSee('id="error-name"', false);
    }
}
