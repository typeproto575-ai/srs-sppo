-- Update the public office schedule to the current parish hours.
UPDATE site_settings
SET setting_value = CONCAT(
    'Monday - Saturday: 8:00 AM - 12:00 NN',
    CHAR(10),
    'Monday - Saturday: 2:00 PM - 5:00 PM',
    CHAR(10),
    'Sunday: Closed'
)
WHERE setting_key = 'office_hours_text';
