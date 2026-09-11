function Edit-IndexPHP {
    param([string]$action, [string]$oldText, [string]$newText, [int]$insertLine)

    $file = "c:\xampp\htdocs\srs-sppo\public\index.php"
    $content = Get-Content -Path $file -Raw

    switch ($action) {
        'replace' {
            if ($oldText -and $newText) {
                $count = [regex]::Matches($content, [regex]::Escape($oldText)).Count
                if ($count -gt 0) {
                    $content = $content -replace [regex]::Escape($oldText), $newText
                    Set-Content -Path $file -Value $content -Encoding UTF8
                    Write-Host "Replaced $count occurrence(s)"
                } else {
                    Write-Host "NOT FOUND: $oldText.Substring(0, [Math]::Min(100, $oldText.Length))..."
                }
            }
        }
        'insert' {
            if ($insertLine) {
                $lines = Get-Content -Path $file
                $lines | ForEach-Object { $_ } | Select-Object -First ($insertLine - 1)
                $lines | ForEach-Object { $_ } | Select-Object -First 0
                $newContent = $lines[0..($insertLine-2)] -join "`n"
                $newContent += "`n$newText`n"
                if ($insertLine -le $lines.Count) {
                    $newContent += ($lines[$insertLine-1..($lines.Count-1)] -join "`n")
                }
                Set-Content -Path $file -Value $newContent -Encoding UTF8
                Write-Host "Inserted at line $insertLine"
            }
        }
    }
}

# 1. Office hours location - replace hardcoded text with settings call
Edit-IndexPHP -action replace `
    -oldText @'
    <h3>Location</h3>
    <p>St. Patrick's Parish Office, Dingalan, Aurora, Philippines</p>
    <p class="field-hint">Certificates and claim slips are released in person at the counter — bring a valid ID.</p>
'@ `
    -newText @'
    <h3>Location</h3>
    <p><?= nl2br(e($settings['office_location_text'])) ?></p>
    <p class="field-hint">Certificates and claim slips are released in person at the counter — bring a valid ID.</p>
'@

# 2. Office hours list replace with settings call  
Edit-IndexPHP -action replace `
    -oldText @'
    <ul>
        <li>Tuesday – Friday: 8:00 AM – 5:00 PM</li>
        <li>Saturday: 8:00 AM – 12:00 NN</li>
        <li>Sunday &amp; Monday: Closed</li>
    </ul>
'@ `
    -newText @'
    <ul class="office-hours-list">
        <?php foreach (explode("\n", $settings['office_hours_text']) as $line): ?>
            <li><?= e($line) ?></li>
        <?php endforeach; ?>
    </ul>
'@

# 3. Calendar legend - replace the h3 with legend inside events heading
Edit-IndexPHP -action replace `
    -oldText '<h3>Upcoming Events</h3>' `
    -newText @'
    <div class="events-heading">
        <h3>Upcoming Events</h3>
        <div class="calendar-legend">
            <span class="legend-item"><span class="legend-swatch swatch-mass"></span> Mass</span>
            <span class="legend-item"><span class="legend-swatch swatch-wedding"></span> Wedding</span>
            <span class="legend-item"><span class="legend-swatch swatch-confirmation"></span> Confirmation</span>
            <span class="legend-item"><span class="legend-swatch swatch-baptism"></span> Baptism</span>
            <span class="legend-item"><span class="legend-swatch swatch-feast"></span> Feast Day</span>
            <span class="legend-item"><span class="legend-swatch swatch-activity"></span> Activity</span>
            <span class="legend-item"><span class="legend-swatch swatch-lecture"></span> Lecture</span>
        </div>
    </div>
'@

# 4. Event cards - add full date/time display
Edit-IndexPHP -action replace `
    -oldText @'
    <?php foreach ($upcomingEvents as $ev): ?>
        <article class="event-card">
            <span class="event-date"><?= e(date("M j", strtotime($ev["event_date"]))) ?></span>
            <div class="event-details">
                <strong><?= e($ev["title"]) ?></strong>
            </div>
            <span class="event-type"><?= e(ucfirst($ev["event_type"])) ?></span>
        </article>
    <?php endforeach; ?>
'@ `
    -newText @'
    <?php foreach ($upcomingEvents as $ev): ?>
        <article class="event-card">
            <span class="event-date"><?= e(date("M j", strtotime($ev["event_date"]))) ?></span>
            <div class="event-details">
                <strong><?= e($ev["title"]) ?></strong>
                <p><?= e(date("l, F j, Y", strtotime($ev["event_date"]))) ?><?= $ev["start_time"] ? " - " . e(date("g:i A", strtotime($ev["start_time"]))) : "" ?></p>
            </div>
            <span class="event-badge event-badge-<?= e($ev["event_type"]) ?>"><?= e(ucfirst($ev["event_type"])) ?></span>
        </article>
    <?php endforeach; ?>
'@

Write-Host "`n=== Verification ===" -ForegroundColor Green
php -l 'c:\xampp\htdocs\srs-sppo\public\index.php' 2>&1 | Select-Object -First 10
