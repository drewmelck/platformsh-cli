<?php
namespace Platformsh\Cli\Command\Tunnel;

use Platformsh\Cli\Util\ProcessManager;
use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TunnelOpenCommand extends TunnelCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('tunnel:open')
            ->setDescription("Open SSH tunnels to an app's relationships");
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();

        if ($environment->id === 'master') {
            $this->stdErr->writeln('Tunnelling to the <error>master</error> environment is not safe.');
            if (count($this->getEnvironments($project)) === 1) {
                $this->stdErr->writeln('Use <info>platform branch</info> to create a new development environment.');
            }
            return 1;
        }

        $appName = $this->selectApp($input);
        $sshUrl = $environment->getSshUrl($appName);

        $util = new RelationshipsUtil($this->stdErr);
        $relationships = $util->getRelationships($sshUrl);
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found.');
            return 1;
        }

        $logFile = $this->getConfigDir() . '/tunnels.log';
        if (!$log = $this->openLog($logFile)) {
            $this->stdErr->writeln(sprintf('Failed to open log file for writing: %s', $logFile));
            return 1;
        }

        $extraSshArgs = [];
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $extraSshArgs[] = '-vv';
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $extraSshArgs[] = '-v';
        }
        $log->setVerbosity($output->getVerbosity());

        $processManager = new ProcessManager();
        $processManager->fork();

        $error = false;
        $processIds = [];
        foreach ($relationships as $relationship => $services) {
            foreach ($services as $serviceKey => $service) {
                $remoteHost = $service['host'];
                $remotePort = $service['port'];

                $localPort = $this->getPort();
                $tunnel = [
                    'projectId' => $project->id,
                    'environmentId' => $environment->id,
                    'appName' => $appName,
                    'relationship' => $relationship,
                    'serviceKey' => $serviceKey,
                    'remotePort' => $remotePort,
                    'remoteHost' => $remoteHost,
                    'localPort' => $localPort,
                    'service' => $service,
                    'pid' => null,
                ];

                $relationshipString = $this->formatTunnelRelationship($tunnel);

                if ($openTunnelInfo = $this->isTunnelOpen($tunnel)) {
                    $this->stdErr->writeln("A tunnel is already open for the relationship: <info>$relationshipString</info>");
                    continue;
                }

                $process = $this->createTunnelProcess($sshUrl, $remoteHost, $remotePort, $localPort, $extraSshArgs);

                $pidFile = $this->getPidFile($tunnel);

                try {
                    $pid = $processManager->startProcess($process, $pidFile, $log);
                }
                catch (\Exception $e) {
                    $this->stdErr->writeln(sprintf('Failed to open tunnel for relationship <error>%s</error>: %s', $relationshipString, $e->getMessage()));
                    $error = true;
                    continue;
                }

                // Wait a very small time to capture any immediate errors.
                usleep(100000);
                if (!$process->isRunning() && !$process->isSuccessful()) {
                    $this->stdErr->writeln(trim($process->getErrorOutput()));
                    $this->stdErr->writeln(sprintf('Failed to open tunnel for relationship: <error>%s</error>', $relationshipString));
                    unlink($pidFile);
                    $error = true;
                    continue;
                }

                // Save information about the tunnel for use in other commands.
                $tunnel['pid'] = $pid;
                $this->tunnelInfo[] = $tunnel;
                $this->saveTunnelInfo();

                $this->stdErr->writeln(sprintf('SSH tunnel opened on port %s to relationship: <info>%s</info>', $localPort, $relationshipString));
                $processIds[] = $pid;
            }
        }

        if (count($processIds)) {
            $this->stdErr->writeln("Logs are written to: $logFile");
        }

        if (!$error) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln("List tunnels with: <info>platform tunnels</info>");
            $this->stdErr->writeln("View tunnel details with: <info>platform tunnel:info</info>");
            $this->stdErr->writeln("Close tunnels with: <info>platform tunnel:close</info>");
        }

        $processManager->killParent($error);

        $processManager->monitor($log);

        return 0;
    }
}
