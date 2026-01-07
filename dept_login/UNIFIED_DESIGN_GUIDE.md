# Unified Design System - Implementation Guide

## Overview
This document provides a comprehensive guide for implementing the unified design system across all pages in the NIRF Portal application.

## Files Created

### 1. Core Design System
- `assets/css/unified-design.css` - Complete CSS framework with variables, components, and utilities
- `unified_header.php` - Unified header with sidebar navigation and department info
- `unified_footer.php` - Unified footer with JavaScript utilities

### 2. Updated Pages
- `dashboard.php` - Completely redesigned dashboard
- `DetailsOfDepartment.php` - Updated with unified form design

## Implementation Steps for Each Page

### Step 1: Update Header Include
Replace the existing header include with the unified header:

```php
// OLD
require "header.php";

// NEW
require "unified_header.php";
```

### Step 2: Update Footer Include
Replace the existing footer include with the unified footer:

```php
// OLD
require "footer.php";

// NEW
require "unified_footer.php";
```

### Step 3: Update Page Structure
Wrap your content in the unified page structure:

```php
<!-- Page Content -->
<div class="page-header">
    <h1 class="page-title">
        <i class="fas fa-icon-name"></i>Page Title
    </h1>
    <p class="page-subtitle">Brief description of the page</p>
</div>

<!-- Your existing content here -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-icon"></i>Section Title
        </h3>
    </div>
    <div class="card-body">
        <!-- Your form/content here -->
    </div>
</div>
```

### Step 4: Update Form Elements
Use the unified form classes:

```php
<!-- Form Groups -->
<div class="form-group">
    <label class="form-label">Field Label</label>
    <input type="text" class="form-control" placeholder="Enter value">
    <div class="form-text">Help text</div>
</div>

<!-- Buttons -->
<button type="submit" class="btn btn-primary">
    <i class="fas fa-save"></i>Save
</button>

<button type="button" class="btn btn-secondary">
    <i class="fas fa-edit"></i>Edit
</button>
```

### Step 5: Update Tables
Use the unified table structure:

```php
<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th>Column 1</th>
                <th>Column 2</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Data 1</td>
                <td>Data 2</td>
            </tr>
        </tbody>
    </table>
</div>
```

## Required Pages to Update

### 1. Profile Page (`profile.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements
- Add page header with title and subtitle

### 2. Programmes Offered (`Programmes_Offered.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements
- Update table styling

### 3. Executive Development Program (`ExecutiveDevelopment.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 4. Student Intake (`IntakeActualStrength.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements
- Update table styling

### 5. Placement Details (`PlacementDetails.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements
- Update table styling

### 6. Salary Details (`SalaryDetails.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 7. Employer Details (`EmployerDetails.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements
- Update table styling

### 8. PhD (`phd.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 9. Faculty Details (`FacultyDetails.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements
- Update table styling

### 10. Faculty Output (`FacultyOutput.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 11. NEP Initiatives (`NEPInitiatives.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 12. Departmental Governance (`Departmental_Governance.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 13. Student Support (`StudentSupport.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 14. Conferences and Workshops (`ConferencesWorkshops.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

### 15. Collaborations (`Collaborations.php`)
- Update header/footer includes
- Wrap content in card structure
- Use unified form elements

## Key Features of the Unified Design

### 1. Responsive Design
- Mobile-first approach
- Flexible grid system
- Responsive typography
- Adaptive components

### 2. Consistent Color Scheme
- Primary: #2563eb (Blue)
- Secondary: #64748b (Gray)
- Success: #10b981 (Green)
- Warning: #f59e0b (Yellow)
- Danger: #ef4444 (Red)

### 3. Typography
- Inter font family
- Consistent font sizes
- Proper line heights
- Clear hierarchy

### 4. Components
- Unified cards
- Consistent buttons
- Standardized forms
- Professional tables
- Modern alerts

### 5. Animations
- Smooth transitions
- Hover effects
- Loading states
- Form validation feedback

## CSS Variables Available

```css
/* Colors */
--primary-color: #2563eb;
--secondary-color: #64748b;
--success-color: #10b981;
--warning-color: #f59e0b;
--danger-color: #ef4444;

/* Spacing */
--spacing-1: 0.25rem;
--spacing-2: 0.5rem;
--spacing-3: 0.75rem;
--spacing-4: 1rem;
--spacing-5: 1.25rem;
--spacing-6: 1.5rem;

/* Border Radius */
--radius-sm: 0.375rem;
--radius-md: 0.5rem;
--radius-lg: 0.75rem;
--radius-xl: 1rem;

/* Shadows */
--shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
--shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
--shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
--shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);

/* Transitions */
--transition-fast: 150ms ease-in-out;
--transition-normal: 300ms ease-in-out;
--transition-slow: 500ms ease-in-out;
```

## JavaScript Utilities Available

The unified footer includes several utility functions:

- `validateForm(formId)` - Form validation
- `showLoading(element)` - Show loading state
- `hideLoading(element)` - Hide loading state
- `showAlert(message, type)` - Show alert messages
- `confirmAction(message, callback)` - Confirmation dialogs
- `formatNumber(num)` - Number formatting
- `isValidEmail(email)` - Email validation
- `isValidPhone(phone)` - Phone validation
- `validatePositiveNumber(input)` - Positive number validation
- `validateYear(input)` - Year validation
- `validatePDF(input)` - PDF file validation

## Benefits of the Unified Design

1. **Consistency**: All pages look and feel the same
2. **Professional**: Modern, clean design
3. **Responsive**: Works on all screen sizes
4. **Accessible**: Proper contrast and focus states
5. **Maintainable**: Centralized CSS variables
6. **User-Friendly**: Intuitive navigation and interactions
7. **Branded**: Consistent with University of Mumbai branding

## Testing Checklist

For each updated page, verify:

- [ ] Header shows correct academic year and department info
- [ ] Sidebar navigation highlights current page
- [ ] Forms use unified styling
- [ ] Buttons have consistent appearance
- [ ] Tables are properly styled
- [ ] Alerts use unified design
- [ ] Page is responsive on different screen sizes
- [ ] All functionality works as expected
- [ ] No broken styles or layout issues

## Support

If you encounter any issues during implementation:

1. Check browser console for errors
2. Verify all CSS variables are loaded
3. Ensure proper HTML structure
4. Test on different screen sizes
5. Validate form functionality

The unified design system provides a solid foundation for a professional, consistent user experience across the entire NIRF Portal application.
