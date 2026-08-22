<?php
require '/app/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book = new Spreadsheet();

$loads = $book->getActiveSheet();
$loads->setTitle('Load Schedule');
$loads->fromArray([
    ['Item', 'Description', 'Crane Class', 'Operating Weight (t)', 'Outrigger Load (t)'],
    ['1', 'Crane pad - north', 'LTM 1095', '95', '58'],
    ['2', 'Crane pad - south', 'LTM 1095', '95', '58'],
    ['3', 'Haul route', 'n/a', '0', '0'],
], null, 'A1');

$stock = $book->createSheet();
$stock->setTitle('Stock');
$stock->fromArray([
    ['Mat Type', 'On Hand', 'Committed', 'Available', 'Depot'],
    ['Heavy duty 3-layer', '420', '260', '160', 'Doncaster'],
    ['Standard 2-layer', '900', '310', '590', 'Doncaster'],
], null, 'A1');

(new Xlsx($book))->save('/fx/load_schedule.xlsx');
echo "wrote load_schedule.xlsx\n";
