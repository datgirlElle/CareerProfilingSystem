<?php

/**
 * Starter careers for the seeded MMCL programs, keyed by program title.
 *
 * Used in two places: db/backfill_program_careers.php copies them into
 * programs.careers, and Careers::effective() falls back to them for any
 * program whose list is still empty, so students see real careers (not the
 * program's own name) even before the backfill has been run. Programs staff
 * add themselves have no starter list; they show no careers until staff list some.
 */
class StarterCareers
{
    public const LISTS = [

    'BA Communication' => ['Public Relations Officer', 'Broadcast Journalist', 'Journalist', 'Content Creator', 'Content Strategist', 'Corporate Communications Specialist', 'Social Media Manager', 'Radio Host'],
    'BS Multimedia Arts' => ['Graphic Designer', 'Animator', 'Video Editor', 'UI/UX Designer', 'Illustrator', 'Photographer', 'Game Artist', 'Motion Graphics Artist'],
    'BS Computer Science' => ['Software Engineer', 'Programmer', 'Game Developer', 'Data Scientist', 'Machine Learning Engineer', 'Systems Analyst', 'Mobile App Developer', 'AI Engineer'],
    'BS Information Technology' => ['Web Developer', 'Network Administrator', 'IT Support Specialist', 'Cybersecurity Analyst', 'Database Administrator', 'Systems Administrator', 'Cloud Engineer'],
    'BS Biology' => ['Research Scientist', 'Biotechnologist', 'Biologist', 'Microbiologist', 'Environmental Scientist', 'Wildlife Biologist', 'Laboratory Analyst'],
    'BS Medical Technology' => ['Medical Technologist', 'Medical Laboratory Scientist', 'Clinical Laboratory Scientist', 'Pathology Laboratory Analyst', 'Blood Bank Technologist'],
    'BS Pharmacy' => ['Pharmacist', 'Clinical Pharmacist', 'Hospital Pharmacist', 'Pharmaceutical Researcher', 'Regulatory Affairs Officer', 'Drug Safety Specialist'],
    'BS Physical Therapy' => ['Physical Therapist', 'Physiotherapist', 'Rehabilitation Specialist', 'Sports Therapist', 'Wellness Coach'],
    'BS Psychology' => ['Psychologist', 'Psychometrician', 'Guidance Counselor', 'Human Resources Specialist', 'Behavioral Analyst', 'Research Assistant'],
    'BS Nursing' => ['Registered Nurse', 'Community Health Nurse', 'Critical Care Nurse', 'Nurse Educator', 'Clinical Nurse Specialist'],
    'BS Accountancy' => ['Certified Public Accountant', 'Accountant', 'Auditor', 'Tax Specialist', 'Forensic Accountant', 'Financial Controller'],
    'BS Accounting Information System' => ['Systems Auditor', 'IT Auditor', 'Accounting Systems Analyst', 'ERP Consultant', 'Financial Analyst'],
    'BS Business Administration Major in Financial Management' => ['Financial Analyst', 'Investment Banker', 'Bank Manager', 'Credit Analyst', 'Financial Planner', 'Treasury Officer'],
    'BS Business Administration Major in Operations Management' => ['Operations Manager', 'Supply Chain Analyst', 'Logistics Coordinator', 'Production Planner', 'Procurement Officer'],
    'BS Business Administration Major in Sustainability Management' => ['Sustainability Manager', 'ESG Analyst', 'Environmental Consultant', 'Corporate Social Responsibility Officer'],
    'BS Hospitality Management' => ['Hotel Manager', 'Restaurant Manager', 'Front Office Manager', 'Chef', 'Event Planner', 'Restaurant Owner', 'Cruise Ship Hospitality Officer'],
    'BS Tourism Management' => ['Travel Consultant', 'Travel Agent', 'Tour Operator', 'Tour Guide', 'Destination Manager', 'Flight Attendant', 'Airline Customer Service Officer'],
    'BS International Business' => ['International Trade Specialist', 'Export Manager', 'Business Development Manager', 'Foreign Market Analyst'],
    'BS Business Analytics with Artificial Intelligence' => ['Business Analyst', 'Data Analyst', 'Data Engineer', 'AI Specialist', 'Business Intelligence Developer', 'Machine Learning Analyst'],
    'BS Marketing' => ['Marketing Manager', 'Brand Manager', 'Digital Marketing Specialist', 'Market Researcher', 'Advertising Executive', 'Sales Manager'],
    'BS Architecture' => ['Architect', 'Licensed Architect', 'Architectural Designer', 'Interior Designer', 'Urban Planner', 'Landscape Architect'],
    'BS Chemical Engineering' => ['Chemical Engineer', 'Process Engineer', 'Quality Control Engineer', 'Environmental Engineer', 'Petrochemical Engineer'],
    'BS Civil Engineering' => ['Civil Engineer', 'Structural Engineer', 'Construction Manager', 'Site Engineer', 'Geotechnical Engineer', 'Transportation Engineer'],
    'BS Mechanical Engineering' => ['Mechanical Engineer', 'HVAC Engineer', 'Manufacturing Engineer', 'Mechatronics Engineer', 'Automotive Engineer', 'Maintenance Engineer'],
    'BS Electrical Engineering' => ['Electrical Engineer', 'Power Systems Engineer', 'Controls Engineer', 'Electrical Design Engineer', 'Electrical Maintenance Engineer'],
    'BS Electronics Engineering' => ['Electronics Engineer', 'Telecommunications Engineer', 'Embedded Systems Engineer', 'Semiconductor Test Engineer', 'Robotics Engineer'],
    'BS Industrial Engineering' => ['Industrial Engineer', 'Production Manager', 'Quality Assurance Manager', 'Process Improvement Analyst', 'Supply Chain Engineer'],
    'BS Computer Engineering' => ['Computer Engineer', 'Embedded Systems Developer', 'Firmware Engineer', 'Hardware Engineer', 'Network Engineer', 'Robotics Engineer'],
    'BS Aeronautical Engineering' => ['Aeronautical Engineer', 'Aerospace Engineer', 'Aircraft Maintenance Engineer', 'Aerospace Design Engineer', 'Flight Test Engineer'],
    'BS Aviation Management' => ['Airport Operations Manager', 'Airline Operations Officer', 'Airline Manager', 'Air Traffic Coordinator', 'Aviation Safety Officer'],
    'BS Marine Engineering' => ['Marine Engineer', 'Ship Engineer Officer', 'Offshore Systems Engineer', 'Port Engineer'],
    'BS Marine Transportation' => ['Deck Officer', 'Navigation Officer', 'Ship Captain', 'Merchant Marine Officer', 'Port Operations Officer', 'Maritime Safety Inspector'],
    ];

    /** @return array<int,string> */
    public static function forProgram(string $title): array
    {
        return self::LISTS[$title] ?? [];
    }
}