<?php

namespace Bluecadet\DrupalPackageManager;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Reports\Report;

class Markdown implements Report {

    protected $colors = FALSE;

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

      $this->colors = $colors = $phpcsFile->config->__get('colors');

      if (!empty($report['messages'])) {

        echo "zzzzzz" . $report['filename'].'>>'.$report['errors'].'>>'.$report['warnings'] . "zzzzzz" . PHP_EOL;

        // echo "___" . PHP_EOL;
        echo "### FILE: " . $report['filename'] . PHP_EOL;
        echo "" . PHP_EOL;

        echo "#### FOUND ";
        if ($report['errors'] > 0) {
          echo $report['errors'] . " ERRORS ";
        }
        if ($report['warnings'] > 0) {
          echo $report['warnings'] . " WARNINGS ";
        }
        echo "".PHP_EOL;


        echo "".PHP_EOL;
        echo "| Line # | Type | Severity | FIX | Message |" . PHP_EOL;
        echo "| -----: | :--: | :------: | :-: | :------ |" . PHP_EOL;

        foreach ($report['messages'] as $line => $lineErrors) {
          foreach ($lineErrors as $column => $colErrors) {
            foreach ($colErrors as $error) {
              echo "| " . $line . " | ";
              echo $colors? "<span style=\"color: " . (($error['type'] == "ERROR")? "red" : "yellow") . "\">" . $error['type'] . "</span>" : $error['type'];
              echo " | " . $error['severity'] . " | ";
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

        return TRUE;
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

        echo "# PHPCS Report" . PHP_EOL;
        echo "" . PHP_EOL;

        echo "## Summary" . PHP_EOL;
        echo "" . PHP_EOL;

        preg_match_all('/zzzzzz(.*)>>(.*)>>(.*)zzzzzz/i', $cachedData, $matches);

        $cachedData = preg_replace('/zzzzzz.*zzzzzz/i', "", $cachedData);
        $data = [];
        foreach ($matches[0] as $i => $match_data) {
          $data[] = [
            $matches[1][$i],
            $matches[2][$i],
            $matches[3][$i],
          ];
        }

        echo "| FILE | ERRORS | WARNINGS |" . PHP_EOL;
        echo "| :--- | :----: | :------: |" . PHP_EOL;

        foreach ($data as $d) {
          echo "| " . $d[0] . " | ";
          echo $this->colors ? "<span style=\"color: red\">" . $d[1] . "</span>" : $d[1];
          echo " | ";
          echo $this->colors ? "<span style=\"color: yellow\">" . $d[2] . "</span>" : $d[2];
          echo " |" . PHP_EOL;
        }

        echo "" . PHP_EOL;

        echo "A TOTAL OF " . $totalErrors . " ERROR";
        if ($totalErrors > 1) echo "S";
        echo " AND " . $totalWarnings . " WARNING";
        if ($totalWarnings > 1) echo "S";
        echo " WERE FOUND IN " . $totalFiles . " FILE";
        if ($totalFiles > 1) echo "S";
        echo "<br>";

        echo "TOTAL FIXABLE: " . $totalFixable . PHP_EOL;
        echo "" . PHP_EOL;



        echo "## Details" . PHP_EOL;
        echo "" . PHP_EOL;

        echo $cachedData;
    }
}
