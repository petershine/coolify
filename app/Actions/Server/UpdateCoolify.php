<?php

namespace App\Actions\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateCoolify
{
    use AsAction;

    public ?Server $server = null;

    public ?string $latestVersion = null;

    public ?string $currentVersion = null;

    public function handle($manual_update = false)
    {
        try {
            $settings = InstanceSettings::get();
            $this->server = Server::find(0);
            if (! $this->server) {
                return;
            }
            CleanupDocker::dispatch($this->server)->onQueue('high');
            $response = Http::retry(3, 1000)->get('https://cdn.coollabs.io/coolify/versions.json');
            if ($response->successful()) {
                $versions = $response->json();
                File::put(base_path('versions.json'), json_encode($versions, JSON_PRETTY_PRINT));
            }
            $this->latestVersion = get_latest_version_of_coolify();
            $this->currentVersion = config('version');
            if (! $manual_update) {
                if (! $settings->is_auto_update_enabled) {
                    return;
                }
                if ($this->latestVersion === $this->currentVersion) {
                    return;
                }
                if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
                    return;
                }
            }
            $this->update();
            $settings->new_version_available = false;
            $settings->save();
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function update()
    {
        if (isDev()) {
            remote_process([
                'sleep 10',
            ], $this->server);

            return;
        }
        remote_process([
            'curl -fsSL https://cdn.coollabs.io/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh',
            "bash /data/coolify/source/upgrade.sh $this->latestVersion",
        ], $this->server);

    }
}
