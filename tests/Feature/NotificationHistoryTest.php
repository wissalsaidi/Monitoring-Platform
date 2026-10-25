<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Historique des notifications (SCRUM-56) : liste de la team, filtres, pagination, lien depuis une alerte.
 */
class NotificationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $viewer;

    private MonitoringTarget $payment;

    private MonitoringTarget $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->admin = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->viewer = User::factory()->create(['name' => 'Victor', 'email' => 'victor@example.com']);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
        $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer]);
        $this->payment = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API']);
        $this->website = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Website-01']);
    }

    private function alert(MonitoringTarget $target, array $attributes = []): Alert
    {
        return Alert::factory()->for($target, 'target')->create([
            'severity' => AlertSeverity::High, 'message' => "{$target->name} response time > 1000 ms", ...$attributes,
        ]);
    }

    private function notify(Alert $alert, User $user, NotificationStatus $status = NotificationStatus::Sent, array $attributes = []): AlertNotification
    {
        return AlertNotification::create([
            'alert_id' => $alert->id, 'user_id' => $user->id, 'recipient' => $user->email,
            'channel' => NotificationChannel::Email, 'event' => NotificationEvent::Opened, 'status' => $status,
            'attempts' => $status === NotificationStatus::Pending ? 0 : 1,
            'sent_at' => $status === NotificationStatus::Sent ? now() : null,
            ...$attributes,
        ]);
    }

    private function page(array $query = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->get(route('teams.notifications.history', [$this->team, ...$query]));
    }

    public function test_the_history_lists_date_alert_recipient_channel_and_status(): void
    {
        $alert = $this->alert($this->payment);
        $this->notify($alert, $this->admin);
        $this->notify($alert, $this->viewer, NotificationStatus::Failed, ['attempts' => 3, 'error' => 'Connection to smtp.internal:25 refused']);

        $this->page()->assertOk()
            ->assertSee('History')
            ->assertSeeInOrder(['Date', 'Alert', 'Event', 'Recipient', 'Channel', 'Status'])
            ->assertSee('Payment API response time &gt; 1000 ms', false)
            ->assertSee('HIGH')
            ->assertSee('Opened')
            ->assertSee(['Alice', 'alice@example.com', 'Victor', 'victor@example.com'])
            ->assertSee('Email')
            ->assertSee(['SENT', 'FAILED', 'after 3 attempts'])
            // Admin : le message d'erreur aide à diagnostiquer le serveur mail
            ->assertSee('Connection to smtp.internal:25 refused');
    }

    public function test_a_viewer_sees_the_history_without_emails_or_smtp_errors(): void
    {
        $alert = $this->alert($this->payment);
        $this->notify($alert, $this->admin, NotificationStatus::Failed, ['attempts' => 3, 'error' => 'Connection to smtp.internal:25 refused']);

        $this->page(user: $this->viewer)->assertOk()
            ->assertSee(['Alice', 'FAILED', 'after 3 attempts'])
            ->assertDontSee('alice@example.com')
            ->assertDontSee('smtp.internal');
    }

    public function test_the_newest_notifications_come_first_and_are_paginated(): void
    {
        $alert = $this->alert($this->payment);
        foreach (range(1, 30) as $i) {
            $user = User::factory()->create(['name' => sprintf('Member %02d', $i)]);
            $this->notify($alert, $user)->forceFill(['created_at' => now()->subMinutes(60 - $i)])->save();
        }

        $this->page()->assertSee('Member 30')->assertSee('Member 06')->assertDontSee('Member 05')->assertSee('page=2', false);
        $this->page(['page' => 2])->assertSee('Member 05')->assertSee('Member 01')->assertDontSee('Member 06');
    }

    public function test_filter_by_status(): void
    {
        $this->notify($this->alert($this->payment), $this->admin);
        $this->notify($this->alert($this->website), $this->viewer, NotificationStatus::Failed);

        $this->page(['status' => 'failed'])->assertSee('Website-01')->assertDontSee('Payment API response time');
        $this->page(['status' => 'sent'])->assertSee('Payment API response time')->assertDontSee('Website-01 response time');
    }

    public function test_filter_by_target(): void
    {
        $this->notify($this->alert($this->payment), $this->admin);
        $this->notify($this->alert($this->website), $this->admin);

        $this->page(['target' => $this->website->id])
            ->assertSee('Website-01 response time')
            ->assertDontSee('Payment API response time');
    }

    public function test_an_alert_links_to_its_notifications(): void
    {
        $alert = $this->alert($this->payment);
        $other = $this->alert($this->payment, ['message' => 'Payment API is down', 'severity' => AlertSeverity::Critical]);
        $this->notify($alert, $this->admin);
        $this->notify($alert, $this->viewer);
        $this->notify($other, $this->admin);
        $silent = $this->alert($this->website);    // aucune notification : pas de lien

        $link = route('teams.notifications.history', [$this->team, 'alert' => $alert->id]);
        $this->actingAs($this->admin)->get(route('alerts.index'))
            ->assertSee(e($link), false)
            ->assertDontSee(e(route('teams.notifications.history', [$this->team, 'alert' => $silent->id])), false);
        $this->actingAs($this->admin)->get(route('targets.show', [$this->payment, 'tab' => 'alerts']))->assertSee(e($link), false);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(e($link), false);

        $this->actingAs($this->admin)->get($link)
            ->assertSee('Alert: Payment API')
            ->assertSee('Payment API response time')
            ->assertDontSee('Payment API is down');
    }

    public function test_another_team_is_out_of_reach(): void
    {
        $other = Team::factory()->create();
        $foreign = MonitoringTarget::factory()->for($other)->create(['name' => 'Secret API']);
        $this->notify($this->alert($foreign), $this->admin);

        $this->page()->assertDontSee('Secret API');
        $this->actingAs($this->admin)->get(route('teams.notifications.history', $other))->assertForbidden();
        // Filtres sur une target ou une alerte d'une autre team : refusés
        $this->page(['target' => $foreign->id])->assertSessionHasErrors('target');
        $this->page(['alert' => Alert::sole()->id])->assertSessionHasErrors('alert');
    }

    public function test_an_invalid_status_is_refused(): void
    {
        $this->page(['status' => 'bounced'])->assertSessionHasErrors('status');
    }

    public function test_a_deleted_account_is_shown_as_deleted_user(): void
    {
        $this->notify($this->alert($this->payment), $this->admin, attributes: ['user_id' => null, 'recipient' => 'deleted user']);

        // Vu par le Viewer (l'email d'Alice, connectée, serait dans le menu du compte)
        $this->page(user: $this->viewer)->assertSee('Deleted user')->assertDontSee('alice@example.com');
    }

    public function test_an_empty_history_explains_what_will_appear(): void
    {
        $this->page()->assertOk()->assertSee('No notification sent yet');
    }

    public function test_the_rules_page_has_the_history_tab(): void
    {
        $this->actingAs($this->viewer)->get(route('teams.notifications.index', $this->team))
            ->assertSee(route('teams.notifications.history', $this->team), false);
    }
}
