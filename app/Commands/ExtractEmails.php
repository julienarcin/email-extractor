<?php

namespace App\Commands;

use function Termwind\{render};
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

class ExtractEmails extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'extract {outputFile=output.csv} {--D|includeDuplicates : Include duplicate emails}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Extract emails';

    /**
     * Patterne mail.
     *
     * @var string
     */
    private $patternEmail = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Title
        $this->newLine();
        $this->line('========================');
        $this->line('SCRAP.IO email extractor');
        $this->line('    Author: Julien Arcin');
        $this->line('========================');
        $this->newLine();

        // Options
        $outputFile = $this->argument('outputFile');
        $onlyUnique = ! $this->option('includeDuplicates');
        $allUniqueEmails = [];

        // Options
        $this->info('Output file: '.$outputFile);
        $this->info('Include duplicates: '.(! $onlyUnique ? 'yes' : 'no'));
        $this->newLine();

        // 1. GET FILES
        // Filter files in current directory
        $allFiles = Storage::allFiles('.');
        $files = [];
        foreach ($allFiles as $file) {
            // Don't loop on output file
            if ($file !== $outputFile) {
                // Add if file extension is csv of txt
                $extension = pathinfo($file, PATHINFO_EXTENSION);

                if ($extension == 'csv' || $extension == 'txt') {
                    $files[] = $file;
                }
            }
        }

        // 2. CHECK IF GOOGLE ID IS PRESENT
        // For each file
        $oneFileHasGoogleId = false;
        foreach ($files as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            // Check if CSV
            if ($extension == 'csv') {
                // 1. Find separator in header
                // Get headers
                $f = fopen($file, 'r');
                $header = fgets($f);
                $separator = ',';

                if (preg_match('/[,;\t]/', $header, $matches)) {
                    $separator = $matches[0];
                }
                rewind($f);

                // 2. Find if column "google id" is present in headers
                $header = fgets($f);
                $header = $this->removeBOM($header);
                $header = $this->removeQuotes($header);
                $headers = explode($separator, $header);

                if ($headers[0] == 'Google ID') {
                    $oneFileHasGoogleId = true;
                }
                fclose($f);
            }
        }

        // 3. INITIALIZE OUTPUT
        if ($oneFileHasGoogleId) {
            Storage::put('./'.$outputFile, 'Email,Google ID');
        } else {
            Storage::put('./'.$outputFile, 'Email');
        }

        // 4. EXTRACT ALL EMAILS
        $nbProcessedFiles = 0;
        $fileHasGoogleId = false;
        foreach ($files as $file) {
            // Ouptut
            $nbProcessedFiles++;
            echo "Processing file $nbProcessedFiles/".count($files)." ($file)...\n";

            // Open file
            $f = fopen($file, 'r');

            // Check extension
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            if ($extension == 'csv') {
                // 1. Find separator in header
                // Get headers
                $f = fopen($file, 'r');
                $header = fgets($f);
                $separator = ',';

                if (preg_match('/[,;\t]/', $header, $matches)) {
                    $separator = $matches[0];
                }
                rewind($f);

                // 2. Count lines
                $nbLines = 0;
                while ($line = fgets($f)) {
                    $nbLines++;
                }
                rewind($f);

                // 3. Get headers
                $header = fgets($f);
                $header = $this->removeBOM($header);
                $header = $this->removeQuotes($header);
                $headers = explode($separator, $header);

                // 4. Find if headers have email, if yes, rewind file to process file, otherwise no
                $processFirstLine = false;
                foreach ($headers as $header) {
                    if (preg_match($this->patternEmail, $header)) {
                        rewind($f);
                    }
                }

                // 5. Find if headers has google id
                if ($headers[0] == 'Google ID') {
                    $fileHasGoogleId = true;
                }

                // 6. Process lines
                $bar = $this->output->createProgressBar($nbLines);
                $bar->setBarCharacter('<fg=green>⚬</>');
                $bar->setEmptyBarCharacter('<fg=red>⚬</>');
                $bar->setProgressCharacter('<fg=green>➤</>');
                $bar->setFormat('very_verbose');
                $bar->start();
                $googleId = 0;
                while ($line = fgets($f)) {
                    // Google ID
                    if ($fileHasGoogleId) {
                        $lineSeparated = explode($separator, $line);
                        $googleId = $lineSeparated[0];
                    }

                    // Capture emails
                    $matches = [];
                    preg_match_all($this->patternEmail, $line, $matches);
                    $emails = $matches[0];
                    $uniqueEmails = array_unique($emails);

                    if (count($uniqueEmails) > 0) {
                        foreach ($uniqueEmails as $email) {
                            if (! $onlyUnique || ! in_array($email, $allUniqueEmails)) {
                                if ($fileHasGoogleId) {
                                    Storage::append('./'.$outputFile, "$email,$googleId");
                                } else {
                                    Storage::append('./'.$outputFile, "$email");
                                }
                                $allUniqueEmails[] = $email;
                            }
                        }
                    }

                    $bar->advance();
                }
                $bar->finish();
                $bar->clear();
                echo " - $nbLines processed!\n";
            } else {
                // 1. Count lines
                $nbLines = 0;
                while ($line = fgets($f)) {
                    $nbLines++;
                }
                rewind($f);

                // 2. Process lines
                $bar = $this->output->createProgressBar($nbLines);
                $bar->setBarCharacter('<fg=green>⚬</>');
                $bar->setEmptyBarCharacter('<fg=red>⚬</>');
                $bar->setProgressCharacter('<fg=green>➤</>');
                $bar->setFormat('very_verbose');
                $bar->start();
                while ($line = fgets($f)) {
                    // Capture emails
                    $matches = [];
                    preg_match_all($this->patternEmail, $line, $matches);
                    $emails = $matches[0];
                    $uniqueEmails = array_unique($emails);

                    if (count($uniqueEmails) > 0) {
                        foreach ($uniqueEmails as $email) {
                            if (! $onlyUnique || ! in_array($email, $allUniqueEmails)) {
                                Storage::append('./'.$outputFile, "$email");
                                $allUniqueEmails[] = $email;
                            }
                        }
                    }

                    $bar->advance();
                }
                $bar->finish();
                $bar->clear();
                echo " - $nbLines processed!\n";
            }
        }
    }

    /**
     * @param $str
     * @return mixed|string
     */
    public function removeBOM($str)
    {
        if (substr($str, 0, 3) === "\xEF\xBB\xBF") {
            $str = substr($str, 3);
        }

        return $str;
    }

    /**
     * @param $str
     * @return mixed|string
     */
    public function removeQuotes($str)
    {
        return preg_replace('/"/', '', $str);
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
