<?php
declare(strict_types=1);

/**
 * Optional wording helpers for the clinic visit form. These are prompts only,
 * not diagnoses or automatic recommendations. Edit the lists to suit clinic policy.
 */

/* Reason / chief-complaint prompts. */
$complaintPrompts = [
    'Headache', 'Dizziness', 'Fever or feeling feverish', 'Cough or cold symptoms',
    'Sore throat', 'Stomach discomfort', 'Nausea', 'Minor injury', 'Skin irritation',
    'Fatigue', 'Request for first aid', 'Request for medical advice',
];

/* Common workplace accident and injury types, for incident reporting. */
$workplaceAccidents = [
    /* Slips, trips and falls */
    'Slip on a wet or slippery floor',
    'Trip over a cable, cord or uneven flooring',
    'Fall from the same level',
    'Fall from a ladder or step stool',
    'Fall on stairs, a mezzanine or from a height',
    'Fall in poor lighting or a blocked walkway',

    /* Struck by, against, or caught in */
    'Struck by a falling object or material',
    'Struck by a moving vehicle or equipment',
    'Struck against a fixed object such as a door or desk',
    'Caught in or between equipment, drawers or doors',
    'Crush injury from a closing door, drawer or gate',

    /* Machinery and tools */
    'Caught in rotating or moving machinery parts',
    'Entanglement in machinery',
    'Loose clothing, hair or jewellery caught in equipment',
    'Injury from a tool or equipment failure',
    'Hand injury from a power tool or hand tool',

    /* Burns, scalds and electrical */
    'Thermal burn from a hot surface or object',
    'Scald from hot liquid or steam',
    'Chemical burn from a spill or splash',
    'Electrical burn or arc flash',
    'Electric shock from faulty equipment or wiring',
    'Friction burn',

    /* Cuts and wounds */
    'Laceration from a knife, cutter or blade',
    'Laceration from broken glass or sharp metal',
    'Puncture wound from a nail, needle or tool',
    'Amputation of a finger or limb',
    'Eye injury from flying debris or a chemical splash',

    /* Overexertion and ergonomics */
    'Muscle strain from lifting or carrying',
    'Sprain from a sudden twist or awkward posture',
    'Back or hernia injury from heavy lifting',
    'Repetitive strain injury from assembly or typing work',
    'Injury from manual handling without equipment',

    /* Exposure and environment */
    'Heat exhaustion or heat stroke',
    'Cold exposure or hypothermia',
    'Inhalation of fumes, dust or gas',
    'Exposure to a toxic or corrosive substance',
    'Skin irritation or allergic reaction from contact',
    'Noise-induced hearing discomfort',

    /* Vehicles and traffic */
    'Vehicle collision in a parking or campus area',
    'Struck by a reversing vehicle',
    'Forklift or pallet jack incident',
    'Injury while loading or unloading',

    /* Fire, biological and other */
    'Fire or explosion injury',
    'Smoke inhalation',
    'Needle-stick or sharps injury',
    'Exposure to blood or bodily fluids',
    'Animal or insect bite or sting',
    'Infection of an open wound',
];

return [
    /* The reason field prompts for both symptoms and accident types. */
    'reason' => array_merge($complaintPrompts, $workplaceAccidents),
    'accident_types' => $workplaceAccidents,
    'employee_report' => [
        'Symptoms started today', 'Symptoms started yesterday', 'Symptoms have been present for several days',
        'Symptoms are improving', 'Symptoms are unchanged', 'Symptoms are worsening',
        'Employee reports pain or discomfort', 'Employee reports feeling weak or fatigued',
        'Employee reports dizziness', 'Employee reports nausea',
    ],
    'assistance_provided' => [
        'First aid provided', 'Employee monitored at the clinic', 'No treatment provided',
    ],
    'advice_given' => [
        'Advised to seek medical assessment', 'Advised to follow existing medical instructions',
        'Advised to report worsening symptoms', 'Follow-up recommended', 'Rest advice documented',
        'Return-to-work documentation requested',
    ],
    'relevant_information' => [
        'Employee reports an existing medical condition on file',
        'Employee is taking medication recorded in the health profile',
        'Allergies recorded in the health profile',
        'Employee has a scheduled medical appointment later today',
        'No relevant health-profile information to add',
    ],

    /* Emergency panel wording. */
    'incident_location' => [
        'Clinic', 'Classroom', 'Laboratory', 'Library', 'Canteen', 'Gymnasium',
        'Sports court or field', 'Comfort room', 'Parking area',
        'Administrative office', 'Other campus area',
    ],
    'circumstances' => [
        'Slipped and fell', 'Collided with another person', 'Struck by a falling object',
        'Sudden onset of symptoms during class', 'Injury during sports or physical activity',
        'Heat-related discomfort', 'Allergic reaction reported',
        'Condition observed while at the workstation', 'Incident reported by a witness',
    ],
    'first_aid' => [
        'Wound cleaned and dressed', 'Ice pack applied', 'Employee rested and monitored',
        'Employee given water and rested', 'Referred to a physician', 'No treatment provided',
    ],
    'actions_taken' => [
        'Employee monitored at the clinic', 'Supervisor notified', 'Security or guard notified',
        'Incident documented', 'Employee sent home with advice', 'Escort arranged',
    ],
    'outcome' => [
        'Returned to class', 'Returned to work', 'Sent home with advice',
        'Referred to an external facility', 'Remained under observation', 'Refused further care',
    ],
];
