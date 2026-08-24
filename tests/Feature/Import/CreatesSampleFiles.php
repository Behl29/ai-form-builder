<?php

namespace Tests\Feature\Import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

trait CreatesSampleFiles
{
    protected function createSampleDocx(string $path, string $type = 'basic'): void
    {
        $phpWord = new PhpWord();

        match ($type) {
            'basic' => $this->createBasicDocx($phpWord),
            'with_headings' => $this->createDocxWithHeadings($phpWord),
            'with_lists' => $this->createDocxWithLists($phpWord),
            'with_table' => $this->createDocxWithTable($phpWord),
            'complex' => $this->createComplexDocx($phpWord),
            default => $this->createBasicDocx($phpWord),
        };

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($path);
    }

    protected function createSampleXlsx(string $path, string $type = 'header'): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        match ($type) {
            'header' => $this->createHeaderXlsx($sheet),
            'mapping' => $this->createMappingXlsx($sheet),
            'ambiguous' => $this->createAmbiguousXlsx($sheet),
            default => $this->createHeaderXlsx($sheet),
        };

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }

    private function createBasicDocx(PhpWord $phpWord): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('Contact Form', 1);
        $section->addText('Name:');
        $section->addText('Email:');
        $section->addText('Phone Number:');
        $section->addText('Message:');
    }

    private function createDocxWithHeadings(PhpWord $phpWord): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('Employee Survey', 1);

        $section->addTitle('Personal Information', 2);
        $section->addText('Full Name:');
        $section->addText('Email Address:');
        $section->addText('Date of Birth:');

        $section->addTitle('Work Experience', 2);
        $section->addText('Years of Experience:');
        $section->addText('Current Position:');
    }

    private function createDocxWithLists(PhpWord $phpWord): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('Preferences Survey', 1);

        $section->addText('Select your preferred contact method:');
        $section->addListItem('Email');
        $section->addListItem('Phone');
        $section->addListItem('SMS');
        $section->addListItem('Mail');

        $section->addText('Which features do you use? (Select all that apply)');
        $section->addListItem('☐ Dashboard');
        $section->addListItem('☐ Reports');
        $section->addListItem('☐ Analytics');
        $section->addListItem('☐ Notifications');
    }

    private function createDocxWithTable(PhpWord $phpWord): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('Registration Form', 1);

        $table = $section->addTable();

        $table->addRow();
        $table->addCell(3000)->addText('Question');
        $table->addCell(3000)->addText('Answer');

        $table->addRow();
        $table->addCell(3000)->addText('First Name:');
        $table->addCell(3000)->addText('_______________');

        $table->addRow();
        $table->addCell(3000)->addText('Last Name:');
        $table->addCell(3000)->addText('_______________');

        $table->addRow();
        $table->addCell(3000)->addText('Email Address:');
        $table->addCell(3000)->addText('_______________');

        $table->addRow();
        $table->addCell(3000)->addText('Phone Number:');
        $table->addCell(3000)->addText('_______________');
    }

    private function createComplexDocx(PhpWord $phpWord): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('Job Application Form', 1);

        $section->addTitle('Personal Details', 2);
        $section->addText('Full Name: *');
        $section->addText('Email Address: *');
        $section->addText('Phone Number:');
        $section->addText('Date of Birth:');
        $section->addText('Website/Portfolio URL:');

        $section->addTitle('Education', 2);
        $section->addText('Highest Degree:');
        $section->addListItem('High School');
        $section->addListItem('Bachelor\'s');
        $section->addListItem('Master\'s');
        $section->addListItem('PhD');

        $section->addTitle('Experience', 2);
        $section->addText('Years of Experience:');
        $section->addText('Describe your relevant experience:');

        $section->addTitle('Preferences', 2);
        $section->addText('Preferred work arrangement:');
        $section->addListItem('☐ Remote');
        $section->addListItem('☐ Hybrid');
        $section->addListItem('☐ On-site');

        $section->addText('Rate your proficiency (1-5):');
    }

    private function createHeaderXlsx($sheet): void
    {
        // Simple header row format
        $sheet->setCellValue('A1', 'Name');
        $sheet->setCellValue('B1', 'Email');
        $sheet->setCellValue('C1', 'Phone');
        $sheet->setCellValue('D1', 'Age');
        $sheet->setCellValue('E1', 'Website');

        // Sample data
        $sheet->setCellValue('A2', 'John Doe');
        $sheet->setCellValue('B2', 'john@example.com');
        $sheet->setCellValue('C2', '555-123-4567');
        $sheet->setCellValue('D2', '30');
        $sheet->setCellValue('E2', 'https://example.com');

        $sheet->setCellValue('A3', 'Jane Smith');
        $sheet->setCellValue('B3', 'jane@example.com');
        $sheet->setCellValue('C3', '555-987-6543');
        $sheet->setCellValue('D3', '25');
        $sheet->setCellValue('E3', 'https://janesmith.com');
    }

    private function createMappingXlsx($sheet): void
    {
        // Explicit mapping format
        $headers = ['section', 'field_type', 'key', 'label', 'placeholder', 'help_text', 'required', 'options', 'validation'];
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue($columns[$col] . '1', $header);
        }

        // Row 2: Text field
        $sheet->setCellValue('A2', 'Personal Info');
        $sheet->setCellValue('B2', 'text');
        $sheet->setCellValue('C2', 'full_name');
        $sheet->setCellValue('D2', 'Full Name');
        $sheet->setCellValue('E2', 'Enter your full name');
        $sheet->setCellValue('F2', 'As it appears on your ID');
        $sheet->setCellValue('G2', 'yes');
        $sheet->setCellValue('H2', '');
        $sheet->setCellValue('I2', 'minLength:2,maxLength:100');

        // Row 3: Email field
        $sheet->setCellValue('A3', 'Personal Info');
        $sheet->setCellValue('B3', 'email');
        $sheet->setCellValue('C3', 'email_address');
        $sheet->setCellValue('D3', 'Email Address');
        $sheet->setCellValue('E3', 'you@example.com');
        $sheet->setCellValue('F3', '');
        $sheet->setCellValue('G3', 'yes');
        $sheet->setCellValue('H3', '');
        $sheet->setCellValue('I3', '');

        // Row 4: Select field
        $sheet->setCellValue('A4', 'Preferences');
        $sheet->setCellValue('B4', 'select');
        $sheet->setCellValue('C4', 'country');
        $sheet->setCellValue('D4', 'Country');
        $sheet->setCellValue('E4', 'Select your country');
        $sheet->setCellValue('F4', '');
        $sheet->setCellValue('G4', 'no');
        $sheet->setCellValue('H4', 'us:United States,uk:United Kingdom,ca:Canada');
        $sheet->setCellValue('I4', '');

        // Row 5: Number field
        $sheet->setCellValue('A5', 'Preferences');
        $sheet->setCellValue('B5', 'number');
        $sheet->setCellValue('C5', 'age');
        $sheet->setCellValue('D5', 'Age');
        $sheet->setCellValue('E5', '');
        $sheet->setCellValue('F5', 'Must be 18 or older');
        $sheet->setCellValue('G5', 'yes');
        $sheet->setCellValue('H5', '');
        $sheet->setCellValue('I5', 'min:18,max:120');

        // Row 6: Checkbox group
        $sheet->setCellValue('A6', 'Preferences');
        $sheet->setCellValue('B6', 'checkbox_group');
        $sheet->setCellValue('C6', 'interests');
        $sheet->setCellValue('D6', 'Interests');
        $sheet->setCellValue('E6', '');
        $sheet->setCellValue('F6', 'Select all that apply');
        $sheet->setCellValue('G6', 'no');
        $sheet->setCellValue('H6', 'tech:Technology,sports:Sports,music:Music,travel:Travel');
        $sheet->setCellValue('I6', '');
    }

    private function createAmbiguousXlsx($sheet): void
    {
        // Headers that could be field names
        $sheet->setCellValue('A1', 'Data');
        $sheet->setCellValue('B1', 'Value');
        $sheet->setCellValue('C1', 'Info');
        $sheet->setCellValue('D1', 'Notes');

        // Ambiguous data
        $sheet->setCellValue('A2', 'ABC123');
        $sheet->setCellValue('B2', 'maybe@email');
        $sheet->setCellValue('C2', '12-34-5678');
        $sheet->setCellValue('D2', 'Some text here');

        $sheet->setCellValue('A3', 'XYZ789');
        $sheet->setCellValue('B3', 'not-an-email');
        $sheet->setCellValue('C3', 'random');
        $sheet->setCellValue('D3', 'More text');
    }

    protected function createMalformedDocx(string $path): void
    {
        // Create a file that looks like docx but isn't valid
        file_put_contents($path, 'This is not a valid DOCX file');
    }

    protected function createMalformedXlsx(string $path): void
    {
        // Create a file that looks like xlsx but isn't valid
        file_put_contents($path, 'This is not a valid XLSX file');
    }
}
