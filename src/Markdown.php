<?php

namespace Bluecadet\DrupalPackageManager;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Reports\Report;

class Markdown implements Report {

    /**
     * Generate a partial report for a single processed file.
     *
     * Function should return TRUE if it printed or stored data about the file
     * and FALSE if it ignored the file. Returning TRUE indicates that the file and
     * its data should be counted in the grand totals.
     *
     * @param array                 $report      Prepared report data.
     * @param \PHP_CodeSniffer\File $phpcsFile   The file being reported on.
     * @param bool                  $showSources Show sources?
     * @param int                   $width       Maximum allowed line width.
     *
     * @return bool
     */
    public function generateFileReport($report, File $phpcsFile, $showSources=false, $width=80) {

      $colors = $phpcsFile->config->__get('colors');

      if (!empty($report['messages'])) {

        echo "zzzzzz" . $report['filename'].'>>'.$report['errors'].'>>'.$report['warnings'] . "zzzzzz" . PHP_EOL;


        echo "___" . PHP_EOL;
        echo "## FILE: " . $report['filename'] . PHP_EOL;
        echo "" . PHP_EOL;

        echo "### FOUND ";
        if ($report['errors'] > 0) {
          echo $report['errors'] . " ERRORS ";
        }
        if ($report['warnings'] > 0) {
          echo $report['warnings'] . " WARNINGS ";
        }
        echo "".PHP_EOL;


        echo "".PHP_EOL;
        echo "| Line # | Severity | FIX | Message |" . PHP_EOL;
        echo "| -----: | :------: | :-: | :------ |" . PHP_EOL;

        foreach ($report['messages'] as $line => $lineErrors) {
          foreach ($lineErrors as $column => $colErrors) {
            foreach ($colErrors as $error) {
              echo "| " . $line . " | ";
              echo $colors? "<span style=\"color: " . (($error['type'] == "ERROR")? "red" : "yellow") . "\">" . $error['type'] . "</span>" : $error['type'];
              echo " | ";
              echo ($error['fixable'])? "[x]" : "[ ]";
              echo " | " . $error['message'];
              if ($showSources) echo "<br>&nbsp;&nbsp;(" . $error['source'] . ")";
              echo " |" . PHP_EOL;
            }
          }
        }


        echo "".PHP_EOL;
        echo "<br><br>".PHP_EOL;
        echo "".PHP_EOL;
      }
    }

    /**
     * Generate the actual report.
     *
     * @param string $cachedData    Any partial report data that was returned from
     *                              generateFileReport during the run.
     * @param int    $totalFiles    Total number of files processed during the run.
     * @param int    $totalErrors   Total number of errors found during the run.
     * @param int    $totalWarnings Total number of warnings found during the run.
     * @param int    $totalFixable  Total number of problems that can be fixed.
     * @param bool   $showSources   Show sources?
     * @param int    $width         Maximum allowed line width.
     * @param bool   $interactive   Are we running in interactive mode?
     * @param bool   $toScreen      Is the report being printed to screen?
     *
     * @return void
     */
    public function generate(
      $cachedData,
      $totalFiles,
      $totalErrors,
      $totalWarnings,
      $totalFixable,
      $showSources=false,
      $width=80,
      $interactive=false,
      $toScreen=true
    ) {
        if ($cachedData === '') {
            return;
        }

        $cachedData = preg_replace('/zzzzzz.*zzzzzz/i', "", $cachedData);

        echo "# PHPCS Report" . PHP_EOL;
        echo "" . PHP_EOL;


        echo "Total Errors: " . $totalErrors . PHP_EOL;
        echo "" . PHP_EOL;


        echo "Total Warnings: " . $totalWarnings . PHP_EOL;
        echo "" . PHP_EOL;


        echo "Total Fixable: " . $totalFixable . PHP_EOL;
        echo "" . PHP_EOL;

        echo $cachedData;
    }
}
