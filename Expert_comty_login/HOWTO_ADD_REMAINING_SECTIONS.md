# How to Add Remaining Sections (15 mins each)

## Section I is COMPLETE - Use It as Your Template!

File: `section1_faculty_output.php` - Study this first!

## Quick Guide: Add Section II (NEP Initiatives)

### Step 1: Copy the Template
```bash
cp section1_faculty_output.php section2_nep_initiatives.php
```

### Step 2: Update Section Header
```php
// Change line ~20:
$sec1 = $dept_data['section_1'] ?? [];
// TO:
$sec2 = $dept_data['section_2'] ?? [];

// Change line ~30:
<h2 class="section-title">
    <i class="fas fa-book"></i> Section II: NEP Initiatives, Teaching, Learning, and Assessment
    <span class="badge bg-primary float-end">Maximum: 100 Marks</span>
</h2>
```

### Step 3: Update Items (6 items)

Replace Items 1-26 with these 6 items:

```php
<?php
// Item 1: NEP Initiatives (2 marks each, max 30)
$nep_count = (int)($sec2['nep_count'] ?? 0);
$auto_score_1 = min($nep_count * 2, 30);
renderVerifiableItem(
    1,
    'NEP Initiatives and Professional Activities adopted (2 marks each, Max 30)',
    $nep_count,
    $auto_score_1,
    30,
    $grouped_docs['nep'] ?? [],
    $is_locked
);

// Item 2: Teaching-learning pedagogies (2 marks each, max 20)
$ped_count = (int)($sec2['ped_count'] ?? 0);
$auto_score_2 = min($ped_count * 2, 20);
renderVerifiableItem(
    2,
    'Teaching-learning pedagogical approaches adopted (2 marks each, Max 20)',
    $ped_count,
    $auto_score_2,
    20,
    [],
    $is_locked
);

// Item 3: Student-centric assessments (2 marks each, max 20)
$assess_count = (int)($sec2['assess_count'] ?? 0);
$auto_score_3 = min($assess_count * 2, 20);
renderVerifiableItem(
    3,
    'Student-centric assessments adopted (2 marks each, Max 20)',
    $assess_count,
    $auto_score_3,
    20,
    [],
    $is_locked
);

// Item 4: MOOC courses (2 marks each, max 10)
$moocs = (int)($sec2['moocs'] ?? 0);
$auto_score_4 = min($moocs * 2, 10);
renderVerifiableItem(
    4,
    'Adoption of MOOC courses in Curriculum (2 marks each, Max 10)',
    $moocs,
    $auto_score_4,
    10,
    [],
    $is_locked
);

// Item 5: E-Content (1 mark per credit, max 15)
$econtent = (int)($sec2['econtent'] ?? 0);
$auto_score_5 = min($econtent, 15);
renderVerifiableItem(
    5,
    'Creation of E-Content Development (1 mark per credit, Max 15)',
    $econtent,
    $auto_score_5,
    15,
    [],
    $is_locked
);

// Item 6: Result declaration (conditional scoring)
$result_days = (int)($sec2['result_days'] ?? 999);
$auto_score_6 = 0;
if ($result_days <= 30) $auto_score_6 = 5;
else if ($result_days <= 45) $auto_score_6 = 2.5;
renderVerifiableItem(
    6,
    'Timely Declaration of Results (Within 30 days: 5 marks, 31-45 days: 2.5 marks, >45 days: 0 marks)',
    "$result_days days",
    $auto_score_6,
    5,
    [],
    $is_locked
);
?>
```

### Step 4: Update Section Total
```php
<div class="mt-4 p-3 bg-light rounded">
    <h4>Section II Total</h4>
    <div class="row">
        <div class="col-md-4">
            <strong>Department Auto Score:</strong>
            <div class="auto-score mt-2"><?php echo number_format($auto_scores['section_2'], 2); ?> / 100</div>
        </div>
        <div class="col-md-4">
            <strong>Expert Score:</strong>
            <div class="expert-score mt-2" id="section_2_total_display"><?php echo number_format($expert_scores['section_2'], 2); ?> / 100</div>
            <input type="hidden" id="expert_section_2_total" value="<?php echo $expert_scores['section_2']; ?>">
        </div>
        <div class="col-md-4">
            <strong>Difference:</strong>
            <div class="mt-2" id="section_2_diff_display"><?php echo number_format($expert_scores['section_2'] - $auto_scores['section_2'], 2); ?></div>
        </div>
    </div>
</div>
```

### Step 5: Update JavaScript
```javascript
function recalculateSection2() {
    let section2Total = 0;
    
    // Get all 6 item scores (change IDs if needed)
    for (let i = 1; i <= 6; i++) {
        const input = document.getElementById('item_nep_initiatives_and_professional_activities_adopted_2_marks_each_max_30') || document.querySelector(`input[id*="item"][id*="${i}"]`);
        if (input) {
            section2Total += parseFloat(input.value) || 0;
        }
    }
    
    section2Total = Math.min(section2Total, 100);
    
    document.getElementById('section_2_total_display').textContent = section2Total.toFixed(2) + ' / 100';
    document.getElementById('expert_section_2_total').value = section2Total;
    document.getElementById('display_expert_section_2').textContent = section2Total.toFixed(2);
    
    const autoScore2 = <?php echo $auto_scores['section_2']; ?>;
    const diff2 = section2Total - autoScore2;
    document.getElementById('section_2_diff_display').textContent = diff2.toFixed(2);
    document.getElementById('display_diff_section_2').textContent = diff2.toFixed(2);
    
    recalculateGrandTotal();
}

function recalculateScores() {
    recalculateSection2();
}
```

### Step 6: Include in review_complete.php
```php
// In review_complete.php, after section1 include:
include('section2_nep_initiatives.php');
```

## Quick Reference for All Sections

### Section II: 6 items, 100 marks
- Item 1: NEP Initiatives (2 × count, max 30)
- Item 2: Pedagogies (2 × count, max 20)
- Item 3: Assessments (2 × count, max 20)
- Item 4: MOOCs (2 × count, max 10)
- Item 5: E-Content (1 × credits, max 15)
- Item 6: Results (conditional, max 5)

### Section III: 21 items, 110 marks
- Item 1: Inclusive practices (1 × count, max 10)
- Item 2: Green practices (1 × count, max 10)
- Items 3-21: Various (expert evaluated, different max marks)
- **Data source:** `$sec3 = $dept_data['section_3']` (from `department_data` table)

### Section IV: 17 items, 140 marks
- Items on intake, diversity, scholarships, placements, achievements
- **Data sources:** Multiple tables (intake, placement, phd, support)
- **Note:** `$sec4 = $dept_data['section_4']` returns array with sub-arrays

### Section V: 11 items, 75 marks
- **Part A:** 6 items (conferences, workshops) - 40 marks
- **Part B:** 5 items (collaborations) - 35 marks
- **Data source:** `$sec5 = $dept_data['section_5']` (conferences & collaborations)

## Pro Tips

1. **Always use `renderVerifiableItem()` for numeric fields**
2. **Use `renderNarrativeItem()` for text descriptions**
3. **Use `renderJSONArrayItems()` for arrays (publications, etc.)**
4. **Copy-paste Section I structure** - it's your best template!
5. **Test each section individually** before moving to next
6. **Check `data_fetcher.php`** - all data is already fetched!

## Common Patterns

### For Simple Counts:
```php
$count = (int)($sec_data['field_name'] ?? 0);
$auto_score = min($count * MARKS_PER_UNIT, MAX_MARKS);
renderVerifiableItem($item_num, $label, $count, $auto_score, MAX_MARKS, [], $is_locked);
```

### For JSON Arrays:
```php
$items = json_decode($sec_data['json_field'] ?? '[]', true);
$count = is_array($items) ? count($items) : 0;
$auto_score = min($count * MARKS_PER_UNIT, MAX_MARKS);
renderJSONArrayItems($item_num, $label, $sec_data['json_field'], $auto_score, MAX_MARKS, [], $is_locked);
```

### For Narrative/Descriptive:
```php
$text = $sec_data['description_field'] ?? '';
renderNarrativeItem($item_num, $label, $text, MAX_MARKS, [], $is_locked);
```

## You're Ready!

With Section I as your reference and this guide, you can complete all remaining sections in under 2 hours total.

Happy coding! 🚀

