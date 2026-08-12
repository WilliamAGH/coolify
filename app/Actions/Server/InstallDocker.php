<?php

namespace App\Actions\Server;

use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallDocker
{
    use AsAction;

    public function handle(Server $server)
    {
        $supported_os_type = $server->validateOS();
        if (! $supported_os_type) {
            throw new \Exception('Server OS type is not supported for automated installation. Please install Docker manually before continuing: <a target="_blank" class="underline" href="https://coolify.io/docs/installation#manually">documentation</a>.');
        }

        if (! $server->sslCertificates()->where('is_ca_certificate', true)->exists()) {
            $serverCert = SslHelper::generateSslCertificate(
                commonName: 'Coolify CA Certificate',
                serverId: $server->id,
                isCaCertificate: true,
                validityDays: 10 * 365
            );
            $caCertPath = config('constants.coolify.base_config_path').'/ssl/';

            $base64Cert = base64_encode($serverCert->ssl_certificate);

            $commands = collect([
                "mkdir -p $caCertPath",
                "chown -R 9999:root $caCertPath",
                "chmod -R 700 $caCertPath",
                "rm -rf $caCertPath/coolify-ca.crt",
                "echo '{$base64Cert}' | base64 -d | tee $caCertPath/coolify-ca.crt > /dev/null",
                "chmod 644 $caCertPath/coolify-ca.crt",
            ]);
            remote_process($commands, $server);
        }

        $config = base64_encode('{
            "log-driver": "json-file",
            "log-opts": {
              "max-size": "10m",
              "max-file": "3"
            }
          }');
        $installer = file_get_contents(base_path('scripts/install.sh'));
        if ($installer === false) {
            throw new \RuntimeException('The canonical Docker daemon configuration owner is unavailable.');
        }
        $encodedInstaller = base64_encode($installer);
        $found = StandaloneDocker::where('server_id', $server->id);
        if ($found->count() == 0 && $server->id) {
            StandaloneDocker::create([
                'name' => 'coolify',
                'network' => 'coolify',
                'server_id' => $server->id,
            ]);
        }
        $command = collect([]);
        if (isDev() && $server->id === 0) {
            $command = $command->merge([
                "echo 'Installing Docker Engine...'",
                "echo 'Configuring Docker Engine (merging existing configuration with the required)...'",
                'sleep 4',
                "echo 'Restarting Docker Engine...'",
                'ls -l /tmp',
            ]);

            return remote_process($command, $server);
        } else {
            $command = $command->merge([
                "echo 'Installing Docker Engine...'",
            ]);

            if ($supported_os_type->contains('debian')) {
                $command = $command->merge([$this->getDebianDockerInstallCommand()]);
            } elseif ($supported_os_type->contains('rhel')) {
                $command = $command->merge([$this->getRhelDockerInstallCommand()]);
            } elseif ($supported_os_type->contains('sles')) {
                $command = $command->merge([$this->getSuseDockerInstallCommand()]);
            } elseif ($supported_os_type->contains('arch')) {
                $command = $command->merge([$this->getArchDockerInstallCommand()]);
            } else {
                $command = $command->merge([$this->getGenericDockerInstallCommand()]);
            }

            $command = $command->merge([
                "echo 'Configuring Docker Engine (merging existing configuration with the required)...'",
                'systemctl enable docker >/dev/null 2>&1 || true',
                "DAEMON_CONFIG_INPUT=\$(mktemp /tmp/coolify-daemon-config.XXXXXX) && DAEMON_CONFIG_RUNNER='' && trap 'rm -f \"\$DAEMON_CONFIG_INPUT\" \"\$DAEMON_CONFIG_RUNNER\"' EXIT && DAEMON_CONFIG_RUNNER=\$(mktemp /tmp/coolify-install.XXXXXX) && echo '{$config}' | base64 -d > \"\$DAEMON_CONFIG_INPUT\" && echo '{$encodedInstaller}' | base64 -d > \"\$DAEMON_CONFIG_RUNNER\" && DAEMON_CONFIG_RESULT=\$(bash \"\$DAEMON_CONFIG_RUNNER\" --configure-docker-daemon /etc/docker/daemon.json \"\$DAEMON_CONFIG_INPUT\" 10.0.0.0/8 24 false false) && rm -f \"\$DAEMON_CONFIG_INPUT\" \"\$DAEMON_CONFIG_RUNNER\" && trap - EXIT && if [ \"\$DAEMON_CONFIG_RESULT\" = changed ]; then echo 'Restarting Docker Engine...'; COOLIFY_SOCKET_MOUNTERS='' && for COOLIFY_CONTAINER in coolify-proxy coolify-sentinel; do if [ \"\$(docker inspect --format='{{.State.Running}}' \"\$COOLIFY_CONTAINER\" 2>/dev/null)\" = true ]; then COOLIFY_SOCKET_MOUNTERS=\"\$COOLIFY_SOCKET_MOUNTERS \$COOLIFY_CONTAINER\"; fi; done && systemctl restart docker && for COOLIFY_CONTAINER in \$COOLIFY_SOCKET_MOUNTERS; do docker restart \"\$COOLIFY_CONTAINER\" >/dev/null; done; else echo 'Docker Engine configuration is up to date'; fi",
            ]);
            if ($server->isSwarm()) {
                $command = $command->merge([
                    'docker network create --attachable --driver overlay coolify-overlay >/dev/null 2>&1 || true',
                ]);
            } else {
                $command = $command->merge([
                    'docker network create --attachable coolify >/dev/null 2>&1 || true',
                ]);
                $command = $command->merge([
                    "echo 'Done!'",
                ]);
            }

            return remote_process($command, $server);
        }
    }

    private function getDebianDockerInstallCommand(): string
    {
        return 'curl -fsSL https://get.docker.com | sh || ('.
            '. /etc/os-release && '.
            'install -m 0755 -d /etc/apt/keyrings && '.
            'curl -fsSL https://download.docker.com/linux/${ID}/gpg -o /etc/apt/keyrings/docker.asc && '.
            'chmod a+r /etc/apt/keyrings/docker.asc && '.
            'echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list && '.
            'apt-get update && '.
            'apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin'.
            ')';
    }

    private function getRhelDockerInstallCommand(): string
    {
        return 'curl -fsSL https://get.docker.com | sh || ('.
            'dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo && '.
            'dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin && '.
            'systemctl start docker && '.
            'systemctl enable docker'.
            ')';
    }

    private function getSuseDockerInstallCommand(): string
    {
        return 'curl -fsSL https://get.docker.com | sh || ('.
            'zypper addrepo https://download.docker.com/linux/sles/docker-ce.repo && '.
            'zypper refresh && '.
            'zypper install -y --no-confirm docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin && '.
            'systemctl start docker && '.
            'systemctl enable docker'.
            ')';
    }

    private function getArchDockerInstallCommand(): string
    {
        return 'pacman -Syu --noconfirm --needed docker docker-compose && '.
            'systemctl enable docker.service && '.
            'systemctl start docker.service';
    }

    private function getGenericDockerInstallCommand(): string
    {
        return 'curl -fsSL https://get.docker.com | sh';
    }
}
