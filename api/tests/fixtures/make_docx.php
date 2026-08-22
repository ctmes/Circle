<?php
require '/app/vendor/autoload.php';

$doc = new \PhpOffice\PhpWord\PhpWord();
$section = $doc->addSection();
$section->addTitle('SCOPE OF WORK - RAIL ACCESS PACKAGE', 1);
$section->addText('Contract ref: SOW-2026-0417');
$section->addTextBreak();
$section->addText('1. Supply of temporary access matting for crane pad and haul route.');
$section->addText('2. Installation during weekend possession only.');
$section->addText('3. Retrieval of all matting within 10 working days of completion.');
$section->addTextBreak();
$section->addText('Mobilisation is required by Monday 14 September 2026.');
$section->addText('Freight confirmation remains outstanding at the time of writing.');

\PhpOffice\PhpWord\IOFactory::createWriter($doc, 'Word2007')->save('/fx/scope_of_work.docx');
echo "wrote scope_of_work.docx\n";
