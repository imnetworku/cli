<?php

namespace Acquia\Ads\Command\Remote;

use Acquia\Ads\Command\CommandBase;
use AcquiaCloudApi\Endpoints\Account;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class DrushCommand
 * A command to proxy Drush commands on an environment using SSH
 * @package Acquia\Ads\Commands\Remote
 */
class AliasesDownloadCommand extends SshCommand
{

    /**
     * {inheritdoc}
     */
    protected function configure()
    {
        $this->setName('remote:aliases:download')
          ->setDescription('Download drush aliases for Acquia Cloud environments');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $acquia_cloud_client = $this->getAcquiaCloudClient();
        $account_adapter = new Account($acquia_cloud_client);
        $aliases = $account_adapter->getDrushAliases();
        $drushArchive = tempnam(sys_get_temp_dir(), 'AcquiaDrushAliases') . '.tar.gz';
        $this->output->writeln(sprintf('Acquia Cloud Drush Aliases archive downloaded to <comment>%s</comment>', $drushArchive));

        if (file_put_contents($drushArchive, $aliases, LOCK_EX)) {
            if (!$home = getenv('HOME')) {
                throw new \Exception('Home directory not found.');
            }
                $drushDirectory = $home . '/.drush';
            if (!is_dir($drushDirectory)) {
                if (!mkdir($drushDirectory, 0700) && !is_dir($drushDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $drushDirectory));
                }
            }
            if (!is_writable($drushDirectory)) {
                chmod($drushDirectory, 0700);
            }
                $archive = new \PharData($drushArchive . '/.drush');
                $drushFiles = [];
            foreach (new \RecursiveIteratorIterator($archive, \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
                $drushFiles[] = '.drush/' . $file->getFileName();
            }

                $archive->extractTo($home, $drushFiles, true);
                $this->output->writeln(sprintf('Acquia Cloud Drush aliases installed into <comment>%s</comment>', $drushDirectory));
                unlink($drushArchive);
        } else {
            $this->logger->error('Unable to download Drush Aliases');
        }

        return 0;
    }
}