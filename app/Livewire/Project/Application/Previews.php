<?php

namespace App\Livewire\Project\Application;

use App\Actions\Docker\GetContainersStatus;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Support\Collection;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class Previews extends Component
{
    public Application $application;
    public string $deployment_uuid;
    public array $parameters;
    public Collection $pull_requests;
    public int $rate_limit_remaining;

    protected $rules = [
        'application.previews.*.fqdn' => 'string|nullable',
    ];
    public function mount()
    {
        $this->pull_requests = collect();
        $this->parameters = get_route_parameters();
    }

    public function load_prs()
    {
        try {
            ['rate_limit_remaining' => $rate_limit_remaining, 'data' => $data] = githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/pulls");
            $this->rate_limit_remaining = $rate_limit_remaining;
            $this->pull_requests = $data->sortBy('number')->values();
        } catch (\Throwable $e) {
            $this->rate_limit_remaining = 0;
            return handleError($e, $this);
        }
    }
    public function save_preview($preview_id)
    {
        try {
            $success = true;
            $preview = $this->application->previews->find($preview_id);
            if (isset($preview->fqdn)) {
                $preview->fqdn = str($preview->fqdn)->replaceEnd(',', '')->trim();
                $preview->fqdn = str($preview->fqdn)->replaceStart(',', '')->trim();
                $preview->fqdn = str($preview->fqdn)->trim()->lower();
                if (!validate_dns_entry($preview->fqdn, $this->application->destination->server)) {
                    $this->dispatch('error', "Validating DNS failed.", "Make sure you have added the DNS records correctly.<br><br>$preview->fqdn->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    $success = false;
                }
                check_domain_usage(resource: $this->application, domain: $preview->fqdn);
            }

            if (!$preview) {
                throw new \Exception('Preview not found');
            }
            $success && $preview->save();
            $success && $this->dispatch('success', 'Preview saved.<br><br>Do not forget to redeploy the preview to apply the changes.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function generate_preview($preview_id)
    {
        $preview = $this->application->previews->find($preview_id);
        if (!$preview) {
            $this->dispatch('error', 'Preview not found.');
            return;
        }
        $fqdn = generateFqdn($this->application->destination->server, $this->application->uuid);

        $url = Url::fromString($fqdn);
        $template = $this->application->preview_url_template;
        $host = $url->getHost();
        $schema = $url->getScheme();
        $random = new Cuid2(7);
        $preview_fqdn = str_replace('{{random}}', $random, $template);
        $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
        $preview_fqdn = str_replace('{{pr_id}}', $preview_id, $preview_fqdn);
        $preview_fqdn = "$schema://$preview_fqdn";
        $preview->fqdn = $preview_fqdn;
        $preview->save();
        $this->dispatch('success', 'Domain generated.');
    }
    public function add(int $pull_request_id, string|null $pull_request_html_url = null)
    {
        try {
            $this->setDeploymentUuid();
            $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
            if (!$found && !is_null($pull_request_html_url)) {
                ApplicationPreview::create([
                    'application_id' => $this->application->id,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url
                ]);
            }
            $this->application->generate_preview_fqdn($pull_request_id);
            $this->application->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
    public function deploy(int $pull_request_id, string|null $pull_request_html_url = null)
    {
        try {
            $this->setDeploymentUuid();
            $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
            if (!$found && !is_null($pull_request_html_url)) {
                ApplicationPreview::create([
                    'application_id' => $this->application->id,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url
                ]);
            }
            queue_application_deployment(
                application: $this->application,
                deployment_uuid: $this->deployment_uuid,
                force_rebuild: false,
                pull_request_id: $pull_request_id,
                git_type: $found->git_type ?? null,
            );
            return redirect()->route('project.application.deployment.show', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $this->deployment_uuid,
                'environment_name' => $this->parameters['environment_name'],
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function setDeploymentUuid()
    {
        $this->deployment_uuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }

    public function stop(int $pull_request_id)
    {
        try {
            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $this->application->destination->server);
            } else {
                $containers = getCurrentApplicationContainerStatus($this->application->destination->server, $this->application->id, $pull_request_id);
                foreach ($containers as $container) {
                    $name = str_replace('/', '', $container['Names']);
                    instant_remote_process(["docker rm -f $name"], $this->application->destination->server, throwError: false);
                }
            }
            GetContainersStatus::dispatchSync($this->application->destination->server);
            $this->application->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(int $pull_request_id)
    {
        try {
            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $this->application->destination->server);
            } else {
                $containers = getCurrentApplicationContainerStatus($this->application->destination->server, $this->application->id, $pull_request_id);
                foreach ($containers as $container) {
                    $name = str_replace('/', '', $container['Names']);
                    instant_remote_process(["docker rm -f $name"], $this->application->destination->server, throwError: false);
                }
            }
            ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first()->delete();
            $this->application->refresh();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function previewRefresh()
    {
        $this->application->previews->each(function ($preview) {
            $preview->refresh();
        });
    }
}
