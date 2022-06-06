<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectDeleteCommand extends CommandBase
{
    protected static $defaultName = 'project:delete';
    protected static $defaultDescription = 'Delete a project';

    private $api;
    private $config;
    private $questionHelper;
    private $selector;

    public function __construct(Api $api, Config $config, QuestionHelper $questionHelper, Selector $selector) {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->selector->addProjectOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($projectId = $input->getArgument('project')) {
            if ($input->getOption('project')) {
                throw new ConsoleInvalidArgumentException(
                    'You cannot use both the <project> argument and the --project option'
                );
            }
            $input->setOption('project', $projectId);
        }
        $project = $this->selector->getSelection($input)->getProject();

        $subscriptionId = $project->getSubscriptionId();
        $subscription = $this->api->loadSubscription($subscriptionId, $project);
        if (!$subscription) {
            $this->stdErr->writeln('Subscription not found: <error>' . $subscriptionId . '</error>');
            $this->stdErr->writeln('Unable to delete the project.');
            return 1;
        }
        // TODO check for a HAL 'delete' link on the subscription?

        $confirmQuestionLines = [
            'You are about to delete the project:',
            '  ' . $this->api->getProjectLabel($project, 'comment'),
            '',
            ' * This action is <options=bold>irreversible</>.',
            ' * Your site will no longer be accessible.',
            ' * All data associated with this project will be deleted, including backups.',
            ' * You will be charged at the end of the month for any remaining project costs.',
            '',
            'Are you sure you want to delete this project?'
        ];
        if (!$this->questionHelper->confirm(implode("\n", $confirmQuestionLines), false)) {
            return 1;
        }

        $title = $project->title;
        if ($input->isInteractive() && strlen($title)) {
            $confirmName = $this->questionHelper->askInput('Type the project title to confirm');
            if ($confirmName !== $title) {
                $this->stdErr->writeln('Incorrect project title (expected: ' . $title . ')');
                return 1;
            }
        }

        try {
            $subscription->delete();
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 403 && !$this->config->getWithDefault('api.organizations', false)) {
                if ($project->owner !== $this->api->getMyUserId()) {
                    $this->stdErr->writeln("Only the project's owner can delete it.");
                    return 1;
                }
            }
            throw $e;
        }

        $this->api->clearProjectsCache();

        $this->stdErr->writeln('');
        $this->stdErr->writeln('The project ' . $this->api->getProjectLabel($project) . ' was deleted.');
        return 0;
    }
}
