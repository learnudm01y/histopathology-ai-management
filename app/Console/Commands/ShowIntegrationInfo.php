<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\ServerName;
use Illuminate\Console\Command;

class ShowIntegrationInfo extends Command
{
    protected $signature = 'info:integration';

    protected $description = 'Show Server IDs, API Keys, AI Model IDs, and App URL for integration';

    public function handle(): void
    {
        $appUrl = config('app.url');

        $this->newLine();
        $this->line('══════════════════════════════════════════════════════');
        $this->info('  INTEGRATION INFO  —  share with your developer');
        $this->line('══════════════════════════════════════════════════════');

        // ── Laravel Domain ────────────────────────────────────────────
        $this->newLine();
        $this->warn('▶  Laravel Domain URL');
        $this->line("   APP_URL = <fg=green>{$appUrl}</>");

        // ── Servers ───────────────────────────────────────────────────
        $this->newLine();
        $this->warn('▶  Servers  (servers_names table)');

        $servers = ServerName::withoutGlobalScopes()
            ->select(['id', 'name', 'type', 'api_url', 'api_key', 'is_active'])
            ->orderBy('id')
            ->get();

        if ($servers->isEmpty()) {
            $this->line('   <fg=red>No servers found.</>');
        } else {
            $rows = $servers->map(fn ($s) => [
                $s->id,
                $s->name,
                $s->type,
                $s->is_active ? '<fg=green>active</>' : '<fg=red>inactive</>',
                $s->api_url ?? '—',
                $s->api_key  ?? '—',
            ])->toArray();

            $this->table(
                ['ID', 'Name', 'Type', 'Status', 'API URL', 'API Key'],
                $rows
            );
        }

        // ── AI Models ─────────────────────────────────────────────────
        $this->newLine();
        $this->warn('▶  AI Models  (ai_models table)');

        $models = AiModel::withoutGlobalScopes()
            ->select(['id', 'name', 'provider', 'model_type', 'is_active', 'is_default'])
            ->orderBy('id')
            ->get();

        if ($models->isEmpty()) {
            $this->line('   <fg=red>No AI models found.</>');
        } else {
            $rows = $models->map(fn ($m) => [
                $m->id,
                $m->name,
                $m->provider ?? '—',
                $m->model_type,
                $m->is_active  ? '<fg=green>active</>  ' : '<fg=red>inactive</>',
                $m->is_default ? '<fg=yellow>default</>' : '',
            ])->toArray();

            $this->table(
                ['ID', 'Name', 'Provider', 'Type', 'Status', 'Default'],
                $rows
            );
        }

        $this->line('══════════════════════════════════════════════════════');
        $this->newLine();
    }
}
