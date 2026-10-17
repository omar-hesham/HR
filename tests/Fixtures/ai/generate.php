<?php

// Synthetic recorded model contract; no provider calls or real candidate data.
$null = ['value' => null, 'evidence' => [], 'confidence' => 0];
$fact = fn ($value, $quote) => ['value' => $value, 'evidence' => [['quote' => $quote]], 'confidence' => .95];
$payload = [
    'schema_version' => '1.0', 'name' => $null, 'contacts' => ['email' => $null, 'phone' => $null], 'city' => null, 'governorate' => null,
    'education' => [['level' => $fact('technical_diploma', 'Industrial diploma, electrical department'), 'field' => $fact('electrical', 'Industrial diploma, electrical department')]],
    'experience_total_years' => $fact(3, '3 years of experience in electrical maintenance'),
    'experience_relevant_years' => [['job_id' => '1', 'years' => $fact(3, '3 years of experience in electrical maintenance')]],
    'previous_roles' => [],
    'skills' => [$fact('plc', 'Diagnosing PLC faults'), $fact('schematics', 'Reading control panel schematics'), $fact('wire_drawing', 'I have not worked on wire-drawing machines')],
    'certifications' => [], 'languages' => [], 'military_status' => $null, 'shift_availability' => $fact('available', 'Available for shift work'),
    'expected_salary' => ['amount' => $null, 'currency' => $null, 'period' => $null],
];
$text = "Name: Samir Hassan\nsamir.hassan@example.test\n01012345678\nIndustrial diploma, electrical department\n3 years of experience in electrical maintenance\nAvailable for shift work\nDiagnosing PLC faults\nReading control panel schematics\nI have not worked on wire-drawing machines\nI accept work at the specified location\nElectrical safety training\n";
file_put_contents(__DIR__.'/worked-response-v1.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
file_put_contents(__DIR__.'/worked-text.txt', $text);
$corpus = [['name' => 'Samir Hassan', 'sender' => 'Hassan Samir', 'filename' => 'Samir_Hassan_CV.pdf', 'text' => "Name: Samir Hassan\nSamir and Hassan\nsamir@example.test\n٠١٠١٢٣٤٥٦٧٨\n+44 20 7946 0958\n29801011234567\nhttps://example.test/profile @samir_h\nAddress: 12 Test Street\nReference: Nabil Farid"]];
file_put_contents(__DIR__.'/masking-corpus.json', json_encode($corpus, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
