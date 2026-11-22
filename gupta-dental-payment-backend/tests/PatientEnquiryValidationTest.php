<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/PatientEnquiryService.php';

$validInput = [
    'full_name' => 'Asha Test',
    'primary_phone' => '+91 98765 43210',
    'alternate_phone' => '',
    'complete_address' => "12 Example Road\nGiridih",
    'age' => '38',
    'email' => 'asha@example.test',
    'reason_for_visit' => 'Routine checkup',
    'consent' => true,
];

$validated = PatientEnquiryService::validate($validInput);

if (
    $validated['primary_phone'] !== '+919876543210' ||
    $validated['alternate_phone'] !== null ||
    $validated['age'] !== 38
) {
    throw new RuntimeException('Valid enquiry normalization failed.');
}

$invalidCases = [
    'invalid phone' => array_merge($validInput, ['primary_phone' => '123']),
    'out-of-range age' => array_merge($validInput, ['age' => '121']),
    'invalid email' => array_merge($validInput, ['email' => 'not-an-email']),
    'unknown reason' => array_merge($validInput, ['reason_for_visit' => 'Unknown']),
    'missing consent' => array_merge($validInput, ['consent' => false]),
    'overlong address' => array_merge(
        $validInput,
        ['complete_address' => str_repeat('x', 501)]
    ),
];

foreach ($invalidCases as $label => $input) {
    try {
        PatientEnquiryService::validate($input);
    } catch (InvalidArgumentException $e) {
        continue;
    }

    throw new RuntimeException('Expected validation failure: ' . $label);
}

$token = str_repeat('a', 64);

if (strlen(PatientEnquiryService::hashRequestToken($token)) !== 64) {
    throw new RuntimeException('Request token hashing failed.');
}

try {
    PatientEnquiryService::hashRequestToken('invalid');
} catch (InvalidArgumentException $e) {
    echo "Patient enquiry validation tests passed.\n";
    exit(0);
}

throw new RuntimeException('Expected invalid request token to be rejected.');
