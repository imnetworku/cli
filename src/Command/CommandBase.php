<?php

namespace Acquia\Cli\Command;

use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Helpers\ClientService;
use Acquia\Cli\Helpers\DataStoreContract;
use Acquia\Cli\Helpers\LocalMachineHelper;
use Acquia\Cli\Helpers\SshHelper;
use Acquia\Cli\Helpers\TelemetryHelper;
use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use AcquiaCloudApi\Connector\Client;
use AcquiaCloudApi\Endpoints\Applications;
use AcquiaCloudApi\Endpoints\Environments;
use AcquiaCloudApi\Endpoints\Logs;
use AcquiaCloudApi\Response\ApplicationResponse;
use AcquiaCloudApi\Response\EnvironmentResponse;
use AcquiaLogstream\LogstreamManager;
use ArrayObject;
use drupol\phposinfo\OsInfo;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;
use Webmozart\KeyValueStore\JsonFileStore;
use Webmozart\PathUtil\Path;
use Zumba\Amplitude\Amplitude;

/**
 * Class CommandBase.
 *
 * @package Grasmash\YamlCli\Command
 */
abstract class CommandBase extends Command implements LoggerAwareInterface {

  use LoggerAwareTrait;

  /**
   * @var \Symfony\Component\Console\Input\InputInterface
   */
  protected $input;

  /**
   * @var \Symfony\Component\Console\Output\OutputInterface
   */
  protected $output;

  /**
   * @var \Symfony\Component\Console\Helper\FormatterHelper*/
  protected $formatter;

  /**
   * @var ApplicationResponse
   */
  private $cloudApplication;

  /**
   * @var array
   */
  protected $localProjectInfo;

  /**
   * @var \Symfony\Component\Console\Helper\QuestionHelper
   */
  protected $questionHelper;

  /**
   * @var \Acquia\Cli\Helpers\TelemetryHelper
   */
  protected $telemetryHelper;

  /**
   * @var LocalMachineHelper
   */
  public $localMachineHelper;

  /**
   * @var JsonFileStore
   */
  protected $datastoreCloud;

  /**
   * @var \Webmozart\KeyValueStore\JsonFileStore
   */
  protected $acliDatastore;

  /**
   * @var string
   */
  protected $cloudConfigFilepath;

  /**
   * @var string
   */
  protected $acliConfigFilename;

  /**
   * @var \Zumba\Amplitude\Amplitude
   */
  protected $amplitude;

  protected $repoRoot;

  /**
   * @var \Acquia\Cli\Helpers\ClientService
   */
  protected $cloudApiClientService;

  /**
   * @var \AcquiaLogstream\LogstreamManager
   */
  protected $logstreamManager;

  /**
   * @var \Acquia\Cli\Helpers\SshHelper
   */
  public $sshHelper;

  /**
   * @var string
   */
  protected $sshDir;

  /**
   * CommandBase constructor.
   *
   * @param string $cloudConfigFilepath
   * @param \Acquia\Cli\Helpers\LocalMachineHelper $localMachineHelper
   * @param \Webmozart\KeyValueStore\JsonFileStore $datastoreCloud
   * @param \Webmozart\KeyValueStore\JsonFileStore $datastoreAcli
   * @param \Acquia\Cli\Helpers\TelemetryHelper $telemetryHelper
   * @param \Zumba\Amplitude\Amplitude $amplitude
   * @param string $acliConfigFilename
   * @param string $repoRoot
   */
  public function __construct(
    string $cloudConfigFilepath,
    LocalMachineHelper $localMachineHelper,
    JsonFileStore $datastoreCloud,
    JsonFileStore $datastoreAcli,
    TelemetryHelper $telemetryHelper,
    Amplitude $amplitude,
    string $acliConfigFilename,
    string $repoRoot,
    ClientService $cloudApiClientService,
    LogstreamManager $logstreamManager,
    SshHelper $sshHelper,
    string $sshDir
  ) {
    $this->cloudConfigFilepath = $cloudConfigFilepath;
    $this->localMachineHelper = $localMachineHelper;
    $this->datastoreCloud = $datastoreCloud;
    $this->acliDatastore = $datastoreAcli;
    $this->telemetryHelper = $telemetryHelper;
    $this->amplitude = $amplitude;
    $this->acliConfigFilename = $acliConfigFilename;
    $this->repoRoot = $repoRoot;
    $this->cloudApiClientService = $cloudApiClientService;
    $this->logstreamManager = $logstreamManager;
    $this->sshHelper = $sshHelper;
    $this->sshDir = $sshDir;
    parent::__construct();
  }

  /**
   * Initializes the command just after the input has been validated.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   An InputInterface instance.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   An OutputInterface instance.
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   * @throws \Exception
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    $this->input = $input;
    $this->output = $output;
    $this->formatter = $this->getHelper('formatter');
    $this->setLogger(new ConsoleLogger($output));

    $this->telemetryHelper->initializeAmplitude($this->amplitude);
    $this->questionHelper = $this->getHelper('question');
    $this->checkAndPromptTelemetryPreference();

    if ($this->commandRequiresAuthentication($this->input) && !self::isMachineAuthenticated($this->datastoreCloud)) {
      throw new AcquiaCliException('This machine is not yet authenticated with Acquia Cloud. Please run `acli auth:login`');
    }

    $this->loadLocalProjectInfo();
  }

  public static function isMachineAuthenticated(JsonFileStore $cloud_datastore): bool {
    return $cloud_datastore !== NULL && $cloud_datastore->get('key') && $cloud_datastore->get('secret');
  }

  /**
   * Check if telemetry preference is set, prompt if not.
   */
  public function checkAndPromptTelemetryPreference(): void {
    $send_telemetry = $this->acliDatastore->get(DataStoreContract::SEND_TELEMETRY);
    if (!isset($send_telemetry) && $this->input->isInteractive()) {
      $this->output->writeln('We strive to give you the best tools for development.');
      $this->output->writeln('You can really help us improve by sharing anonymous performance and usage data.');
      $question = new ConfirmationQuestion('<question>Would you like to share anonymous performance usage and data?</question> ', TRUE);
      $pref = $this->questionHelper->ask($this->input, $this->output, $question);
      $this->acliDatastore->set(DataStoreContract::SEND_TELEMETRY, $pref);
      if ($pref) {
        $this->output->writeln('Awesome! Thank you for helping!');
      }
      else {
        // @todo Completely anonymously send an event to indicate some user opted out.
        $this->output->writeln('Ok, no data will be collected and shared with us.');
        $this->output->writeln('We take privacy seriously.');
        $this->output->writeln('If you change your mind, run <options=bold>acli telemetry</>.');
      }
    }
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return int
   * @throws \Exception
   */
  public function run(InputInterface $input, OutputInterface $output) {
    $exit_code = parent::run($input, $output);
    $event_properties = [
      'exit_code' => $exit_code,
      'arguments' => $input->getArguments(),
      'options' => $input->getOptions(),
      'app_version' => $this->getApplication()->getVersion(),
      // phpcs:ignore
      'platform' => OsInfo::family(),
      'os_name' => OsInfo::os(),
      'os_version' => OsInfo::version(),
    ];
    $this->amplitude->queueEvent('Ran command', $event_properties);

    return $exit_code;
  }

  /**
   * Indicates whether the command requires the machine to be authenticated with Acquia Cloud.
   *
   * @param $input
   *
   * @return bool
   */
  protected function commandRequiresAuthentication(InputInterface $input): bool {
    // In fact some other commands such as `api:list` don't require auth, but it's easier and safer to assume they do.
    return $input->getFirstArgument() !== 'auth:login';
  }

  /**
   * Prompts the user to choose from a list of available Acquia Cloud applications.
   *
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   *
   * @return null|object|array
   */
  protected function promptChooseApplication(
    Client $acquia_cloud_client
  ) {
    $applications_resource = new Applications($acquia_cloud_client);
    $customer_applications = $applications_resource->getAll();
    return $this->promptChooseFromObjects(
      $customer_applications,
      'uuid',
      'name',
      'Please select an Acquia Cloud application:'
    );
  }

  /**
   * Prompts the user to choose from a list of environments for a given Acquia Cloud application.
   *
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   * @param string $application_uuid
   *
   * @return null|object|array
   */
  protected function promptChooseEnvironment(
    Client $acquia_cloud_client,
    string $application_uuid
  ) {
    $environment_resource = new Environments($acquia_cloud_client);
    $environments = $environment_resource->getAll($application_uuid);
    return $this->promptChooseFromObjects(
      $environments,
      'uuid',
      'name',
      'Please select an Acquia Cloud environment:'
    );
  }

  /**
   * Prompts the user to choose from a list of logs for a given Acquia Cloud environment.
   *
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   * @param string $environment_id
   *
   * @return null|object|array
   */
  protected function promptChooseLogs(
    Client $acquia_cloud_client,
    string $environment_id
  ) {
    $logs_resource = new Logs($acquia_cloud_client);
    $logs = $logs_resource->getAll($environment_id);

    return $this->promptChooseFromObjects(
      $logs,
      'type',
      'label',
      'Please select one or more logs as a comma-separated list:',
      TRUE
    );
  }

  /**
   * Prompt a user to choose from a list.
   *
   * The list is generated from an array of objects. The objects much have at least one unique property and one
   * property that can be used as a human readable label.
   *
   * @param object[]|ArrayObject $items An array of objects.
   * @param string $unique_property The property of the $item that will be used to identify the object.
   * @param string $label_property
   * @param string $question_text
   *
   * @param bool $multiselect
   *
   * @return null|object|array
   */
  public function promptChooseFromObjects($items, $unique_property, $label_property, $question_text, $multiselect = FALSE) {
    $list = [];
    foreach ($items as $item) {
      $list[$item->$unique_property] = trim($item->$label_property);
    }
    $labels = array_values($list);
    $question = new ChoiceQuestion($question_text, $labels);
    $question->setMultiselect($multiselect);
    $helper = $this->getHelper('question');
    $choice_id = $helper->ask($this->input, $this->output, $question);
    if (!$multiselect) {
      $identifier = array_search($choice_id, $list, TRUE);
      foreach ($items as $item) {
        if ($item->$unique_property === $identifier) {
          return $item;
        }
      }
    }
    else {
      $chosen = [];
      foreach ($choice_id as $choice) {
        $identifier = array_search($choice, $list, TRUE);
        foreach ($items as $item) {
          if ($item->$unique_property === $identifier) {
            $chosen[] = $item;
          }
        }
      }
      return $chosen;
    }

    return NULL;
  }

  /**
   * Load local project info from the ACLI datastore. If none exists, create default info and set it.
   * @throws \Exception
   */
  protected function loadLocalProjectInfo() {
    $this->logger->debug('Loading local project information...');
    $local_user_config = $this->acliDatastore->get($this->acliConfigFilename);
    // Save empty local project info.
    // @todo Abstract this.
    if ($local_user_config !== NULL && $this->repoRoot !== NULL) {
      $this->logger->debug('Searching local datastore for matching project...');
      foreach ($local_user_config['localProjects'] as $project) {
        if ($project['directory'] === $this->repoRoot) {
          $this->logger->debug('Matching local project found.');
          $this->localProjectInfo = $project;
          return;
        }
      }
    }
    else {
      $this->logger->debug('No matching local project found.');
      $local_user_config = [];
    }

    if ($this->repoRoot) {
      $this->createLocalProjectStubInConfig($local_user_config);
    }
  }

  /**
   * Load configuration from .git/config.
   *
   * @return array|null
   */
  protected function getGitConfig(): ?array {
    $file_path = $this->repoRoot . '/.git/config';
    if (file_exists($file_path)) {
      return parse_ini_file($file_path, TRUE);
    }

    return NULL;
  }

  /**
   * Gets an array of git remotes from a .git/config array.
   *
   * @param array $git_config
   *
   * @return array
   *   A flat array of git remote urls.
   */
  protected function getGitRemotes(array $git_config): array {
    $local_vcs_remotes = [];
    foreach ($git_config as $section_name => $section) {
      if ((strpos($section_name, 'remote ') !== FALSE) &&
        (strpos($section['url'], 'acquia.com') || strpos($section['url'], 'acquia-sites.com'))
      ) {
        $local_vcs_remotes[] = $section['url'];
      }
    }

    return $local_vcs_remotes;
  }

  /**
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   * @param array $local_git_remotes
   *
   * @return \AcquiaCloudApi\Response\ApplicationResponse|null
   */
  protected function findCloudApplicationByGitUrl(
        Client $acquia_cloud_client,
        array $local_git_remotes
    ): ?ApplicationResponse {

    // Set up API resources.
    $applications_resource = new Applications($acquia_cloud_client);
    $customer_applications = $applications_resource->getAll();
    $environments_resource = new Environments($acquia_cloud_client);

    // Create progress bar.
    $count = count($customer_applications);
    $progressBar = new ProgressBar($this->output, $count);
    $progressBar->setFormat('message');
    $progressBar->setMessage("Searching <options=bold>$count applications</> on Acquia Cloud...");
    $progressBar->start();

    // Search Cloud applications.
    foreach ($customer_applications as $application) {
      $progressBar->setMessage("Searching <options=bold>{$application->name}</> for matching git URLs");
      $application_environments = $environments_resource->getAll($application->uuid);
      if ($application = $this->searchApplicationEnvironmentsForGitUrl(
            $application,
            $application_environments,
            $local_git_remotes
        )) {
        $progressBar->finish();
        $progressBar->clear();

        return $application;
      }
      $progressBar->advance();
    }
    $progressBar->finish();
    $progressBar->clear();

    return NULL;
  }

  /**
   * @param \AcquiaCloudApi\Response\ApplicationResponse $application
   * @param \AcquiaCloudApi\Response\EnvironmentsResponse $application_environments
   * @param array $local_git_remotes
   *
   * @return \AcquiaCloudApi\Response\ApplicationResponse|null
   */
  protected function searchApplicationEnvironmentsForGitUrl(
        $application,
        $application_environments,
        $local_git_remotes
    ): ?ApplicationResponse {
    foreach ($application_environments as $environment) {
      if ($environment->flags->production && in_array($environment->vcs->url, $local_git_remotes, TRUE)) {
        $this->logger->debug("Found matching Cloud application! {$application->name} with uuid {$application->uuid} matches local git URL {$environment->vcs->url}");

        return $application;
      }
    }

    return NULL;
  }

  /**
   * Infer which Acquia Cloud application is associated with the current local git repository.
   *
   * If the local git repository has a remote with a URL that matches an Acquia Cloud application's VCS URL, assume
   * that we have a match.
   *
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   *
   * @return \AcquiaCloudApi\Response\ApplicationResponse|null
   */
  protected function inferCloudAppFromLocalGitConfig(
    Client $acquia_cloud_client
    ): ?ApplicationResponse {
    if ($this->repoRoot) {
      $this->output->writeln("There is no Acquia Cloud application linked to <options=bold>{$this->repoRoot}/.git</>.");
      $question = new ConfirmationQuestion('<question>Would you like Acquia CLI to search for a Cloud application that matches your local git config?</question> ');
      $helper = $this->getHelper('question');
      $answer = $helper->ask($this->input, $this->output, $question);
      if ($answer) {
        $this->output->writeln('Searching for a matching Cloud application...');
        if ($git_config = $this->getGitConfig()) {
          $local_git_remotes = $this->getGitRemotes($git_config);
          if ($cloud_application = $this->findCloudApplicationByGitUrl($acquia_cloud_client,
            $local_git_remotes)) {
            $this->output->writeln('<info>Found a matching application!</info>');
            return $cloud_application;
          }
          else {
            $this->output->writeln('<comment>Could not find a matching Cloud application.</comment>');
            return NULL;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Determine the Cloud environment.
   *
   * @param string $application_uuid
   *
   * @return mixed
   * @throws \Exception
   */
  protected function determineCloudEnvironment($application_uuid) {
    $acquia_cloud_client = $this->cloudApiClientService->getClient();
    $environment = $this->promptChooseEnvironment($acquia_cloud_client, $application_uuid);

    return $environment->uuid;

  }

  /**
   * Determine the Cloud application.
   *
   * @param bool $link_app
   *
   * @return string|null
   * @throws \Exception
   */
  protected function determineCloudApplication($link_app = FALSE): ?string {
    $application_uuid = $this->doDetermineCloudApplication();
    if (isset($application_uuid)) {
      $application = $this->getCloudApplication($application_uuid);
      if (!$this->getAppUuidFromLocalProjectInfo()) {
        if ($link_app) {
          $this->saveLocalConfigCloudAppUuid($application);
        }
        elseif (!AcquiaDrupalEnvironmentDetector::isAhIdeEnv()) {
          // @todo Don't prompt if the user already has this linked in blt.yml.
          $this->promptLinkApplication($application);
        }
      }
    }

    return $application_uuid;
  }

  /**
   * @return array|false|mixed|string|null
   * @throws \Exception
   */
  protected function doDetermineCloudApplication() {
    $acquia_cloud_client = $this->cloudApiClientService->getClient();

    if ($this->input->hasOption('cloud-app-uuid') && $this->input->getOption('cloud-app-uuid')) {
      $cloud_application_uuid = $this->input->getOption('cloud-app-uuid');
      return $this->validateUuid($cloud_application_uuid);
    }

    // Try local project info.
    if ($application_uuid = $this->getAppUuidFromLocalProjectInfo()) {
      return $application_uuid;
    }

    // Try blt.yml.
    $blt_yaml_file_path = Path::join($this->repoRoot, 'blt', 'blt.yml');
    if (file_exists($blt_yaml_file_path)) {
      $contents = Yaml::parseFile($blt_yaml_file_path);
      if (array_key_exists('cloud', $contents) && array_key_exists('appId', $contents['cloud'])) {
        $this->logger->debug('Using Cloud application UUID ' . $contents['cloud']['appId'] . ' from blt/blt.yml');
        return $contents['cloud']['appId'];
      }
    }

    // Get from Acquia Cloud env var.
    if ($application_uuid = self::getThisCloudIdeCloudAppUuid()) {
      return $application_uuid;
    }

    // Try to guess based on local git url config.
    if ($cloud_application = $this->inferCloudAppFromLocalGitConfig($acquia_cloud_client)) {
      return $cloud_application->uuid;
    }

    // Finally, just ask.
    if ($application = $this->promptChooseApplication($acquia_cloud_client)) {
      return $application->uuid;
    }

    return NULL;
  }

  /**
   * @param string $uuid
   *
   * @return mixed
   */
  protected function validateUuid($uuid) {
    $violations = Validation::createValidator()->validate($uuid, [
      new NotBlank(),
      new Uuid(),
    ]);
    if (count($violations)) {
      throw new ValidatorException($violations->get(0)->getMessage());
    }

    return $uuid;
  }

  /**
   * @param \AcquiaCloudApi\Response\ApplicationResponse $application
   *
   * @return bool
   * @throws \Exception
   */
  protected function saveLocalConfigCloudAppUuid(ApplicationResponse $application): bool {
    $local_user_config = $this->acliDatastore->get($this->acliConfigFilename);
    if (!$local_user_config) {
      $local_user_config = [
        'localProjects' => [],
      ];
    }
    foreach ($local_user_config['localProjects'] as $key => $project) {
      if ($project['directory'] === $this->repoRoot) {
        $project['cloud_application_uuid'] = $application->uuid;
        $local_user_config['localProjects'][$key] = $project;
        $this->localProjectInfo = $local_user_config;
        $this->acliDatastore->set($this->acliConfigFilename, $local_user_config);
        $this->output->writeln("<info>The Cloud application <options=bold>{$application->name}</> has been linked to this repository</info>");

        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return mixed
   */
  protected function getAppUuidFromLocalProjectInfo() {
    if (isset($this->localProjectInfo) && array_key_exists('cloud_application_uuid', $this->localProjectInfo)) {
      return $this->localProjectInfo['cloud_application_uuid'];
    }

    return NULL;
  }

  /**
   * @param \AcquiaCloudApi\Response\ApplicationResponse|null $cloud_application
   *
   * @return bool
   * @throws \Exception
   */
  protected function promptLinkApplication(
    ?ApplicationResponse $cloud_application
    ): bool {
    $question = new ConfirmationQuestion("<question>Would you like to link the Cloud application <bg=cyan;options=bold>{$cloud_application->name}</> to this repository</question>? ");
    $helper = $this->getHelper('question');
    $answer = $helper->ask($this->input, $this->output, $question);
    if ($answer) {
      return $this->saveLocalConfigCloudAppUuid($cloud_application);
    }
    return FALSE;
  }

  /**
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function validateCwdIsValidDrupalProject(): void {
    if (!$this->repoRoot) {
      throw new AcquiaCliException('Could not find a local Drupal project. Looked for `docroot/index.php` in current and parent directories. Please execute this command from within a Drupal project directory.');
    }
  }

  /**
   * Determines if Acquia CLI is being run from within a Cloud IDE.
   *
   * @return bool
   *   TRUE if Acquia CLI is being run from within a Cloud IDE.
   */
  public static function isAcquiaCloudIde(): bool {
    return AcquiaDrupalEnvironmentDetector::getAhEnv() === 'IDE';
  }

  /**
   * Get the Cloud Application UUID from a Cloud IDE's environmental variable.
   *
   * This command assumes it is being run inside of a Cloud IDE.
   *
   * @return array|false|string
   */
  protected static function getThisCloudIdeCloudAppUuid() {
    return getenv('ACQUIA_APPLICATION_UUID');
  }

  /**
   * Get the UUID from a Cloud IDE's environmental variable.
   *
   * This command assumes it is being run inside of a Cloud IDE.
   *
   * @return false|string
   */
  public static function getThisCloudIdeUuid() {
    return getenv('REMOTEIDE_UUID');
  }

  /**
   * @param array $local_user_config
   *
   * @throws \Exception
   */
  protected function createLocalProjectStubInConfig(
    array $local_user_config
  ): void {
    $project = [];
    $project['name'] = basename($this->repoRoot);
    $project['directory'] = $this->repoRoot;
    $local_user_config['localProjects'][] = $project;

    $this->localProjectInfo = $local_user_config;
    $this->logger->debug('Saving local project information.');
    $this->acliDatastore->set($this->acliConfigFilename, $local_user_config);
  }

  /**
   * @param $application_uuid
   *
   * @return \AcquiaCloudApi\Response\ApplicationResponse
   * @throws \Exception
   */
  protected function getCloudApplication($application_uuid): ApplicationResponse {
    $applications_resource = new Applications($this->cloudApiClientService->getClient());

    return $applications_resource->get($application_uuid);
  }

  /**
   * @param $environment_id
   *
   * @return \AcquiaCloudApi\Response\EnvironmentResponse
   * @throws \Exception
   */
  protected function getCloudEnvironment($environment_id): EnvironmentResponse {
    $environment_resource = new Environments($this->cloudApiClientService->getClient());

    return $environment_resource->get($environment_id);
  }

  /**
   * @param string $command_name
   * @param array $arguments
   *
   * @return int
   * @throws \Exception
   */
  protected function executeAcliCommand($command_name, $arguments = []): int {
    $command = $this->getApplication()->find($command_name);
    array_unshift($arguments, ['command' => $command_name]);
    $create_input = new ArrayInput($arguments);

    return $command->run($create_input, new NullOutput());
  }

}
