<?php

namespace Laravel\Installer\Console;

use Illuminate\Support\ProcessUtils;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use GuzzleHttp\Client;
use ZipArchive;

use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use Concerns\ConfiguresPrompts;

    /**
     * The Composer instance.
     *
     * @var \Illuminate\Support\Composer
     */
    protected $composer;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Beekman Laravel application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Interact with the user before validating the input.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(PHP_EOL.'Beekman Laravel installer'.PHP_EOL.PHP_EOL);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: 'What is the name of your project?',
                placeholder: 'E.g. example-app',
                required: 'The project name is required.',
                validate: fn ($value) => preg_match('/[^\pL\pN\-_.]/', $value) !== 0
                    ? 'The name may only contain letters, numbers, dashes, underscores, and periods.'
                    : null,
            ));
        }
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            throw new RuntimeException('The Beekman Laravel installer requires PHP 8.1.0 or greater.');
        }

        if (! extension_loaded('zip')) {
            throw new RuntimeException('The Zip PHP extension is not installed. Please install it and try again.');
        }

        $name = $input->getArgument('name');

        $directory = $name !== '.' ? getcwd().'/'.$name : '.';

        $this->composer = new Composer(new Filesystem(), $directory);

        if (! $input->getOption('force')) {
            // $this->verifyApplicationDoesntExist($directory);
        }

        $this->info($output, "Application installing in <options=bold>[{$directory}]</>.");

//        $this->runTask($output, 'Extracting base application', function() use ($directory) {
//            $zipFile = $this->makeFilename();
//
//            $this->download($zipFile)
//                ->extract($zipFile, $directory)
//                ->cleanUp($zipFile);
//        });

        $this->runTask($output, 'Running beekman installer', function() use ($output, $directory) {
            $commands = [
                $this->phpBinary().' artisan beekman:install',
            ];

            $process = Process::fromShellCommandline(implode(' && ', $commands), $directory, null, null, null);

            if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
                try {
                    $process->setTty(true);
                } catch (RuntimeException $exception) {
                    $this->error($output, $exception->getMessage());
                }
            }

            $process->run(function ($type, $line) use ($output) {
                $this->info($output, $line);
            });

            if ($process->isSuccessful()) {
                $this->info($output, 'Success');
            }
        });

        return 0;
    }

    private function runTask(OutputInterface $output, string $message, callable $function)
    {
        $this->startAction($output, $message);
        $function();
        $this->finishAction($output, $message);
    }

    /**
     * Output info message
     *
     * @param  OutputInterface  $output
     * @param  string  $message
     * @return void
     */
    private function info(OutputInterface $output, string $message)
    {
        $message = trim($message);
        $output->writeln("<bg=blue;fg=white> INFO  </> {$message}".PHP_EOL);
    }

    /**
     * Output error message
     *
     * @param  OutputInterface  $output
     * @param  string  $message
     * @return void
     */
    private function error(OutputInterface $output, string $message)
    {
        $message = trim($message);
        $output->writeln("<bg=red;fg=white> ERROR </> {$message}".PHP_EOL);
    }

    /**
     * Output start action message
     *
     * @param  OutputInterface  $output
     * @param  string  $message
     * @return void
     */
    private function startAction(OutputInterface $output, string $message)
    {
        $message = trim($message);
        $output->writeln("<bg=yellow;fg=bright-yellow> START </> {$message}".PHP_EOL);
    }

    /**
     * Output finish action message
     *
     * @param  OutputInterface  $output
     * @param  string  $message
     * @return void
     */
    private function finishAction(OutputInterface $output, string $message)
    {
        $message = trim($message);
        $output->writeln("<bg=green;fg=white> DONE  </> {$message}".PHP_EOL);
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/laravel_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function download($zipFile)
    {
        $response = (new Client(['verify' => false ]))->get('https://gitlab.beekman.nl/developers/beekman-laravel/-/archive/master/beekman-laravel-master.zip');

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the Zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @return $this
     */
    protected function extract($zipFile, $directory)
    {
        $archive = new ZipArchive;

        $response = $archive->open($zipFile, ZipArchive::CHECKCONS);

        if ($response === ZipArchive::ER_NOZIP) {
            throw new RuntimeException('The zip file could not download. Verify that you are able to access: https://github.com/AIbnuHIbban/larawire-installer/raw/master/v1.0.zip');
        }
        $archive->extractTo('./');

        $archive->close();

        rename('beekman-laravel-master', $directory);

        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);

        @unlink($zipFile);

        return $this;
    }

    /**
     * Configure the default database connection.
     *
     * @param  string  $directory
     * @param  string  $database
     * @param  string  $name
     * @return void
     */

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    /**
     * Get the path to the appropriate PHP binary.
     *
     * @return string
     */
    protected function phpBinary()
    {
        $phpBinary = (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }
}
