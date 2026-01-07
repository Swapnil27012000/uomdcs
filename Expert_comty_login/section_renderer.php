<?php
/**
 * Section Renderer - Reusable functions to render verification fields
 * This makes the code maintainable and extensible
 */

// Include data_fetcher for getProgramSpecificDocuments function
if (!function_exists('getProgramSpecificDocuments')) {
    require_once(__DIR__ . '/data_fetcher.php');
}

/**
 * Helper to render department-submitted value (supports strings/arrays)
 */
function renderDeptValueContent($dept_value) {
    if (is_array($dept_value)) {
        if (empty($dept_value)) {
            echo '<span class="text-muted">No data provided</span>';
            return;
        }
        echo '<div class="dept-value-list">';
        foreach ($dept_value as $entry) {
            if (is_array($entry)) {
                echo '<div class="dept-entry mb-2 p-2 bg-white border rounded">';
                foreach ($entry as $key => $value) {
                    // Filter out "-" and empty values
                    if ($value !== '-' && trim($value) !== '') {
                        echo '<div><em>' . htmlspecialchars((string)$key) . ':</em> ' . htmlspecialchars((string)$value) . '</div>';
                    }
                }
                echo '</div>';
            } else {
                // Filter out "-" and empty values
                if ($entry !== '-' && trim($entry) !== '') {
                    echo '<div class="dept-entry">• ' . htmlspecialchars((string)$entry) . '</div>';
                }
            }
        }
        echo '</div>';
    } else {
        // Filter out "-" and treat as empty
        $display_value = isset($dept_value) && $dept_value !== '' && $dept_value !== '-' 
            ? (string)$dept_value 
            : 'N/A';
        echo htmlspecialchars($display_value);
    }
}

/**
 * Render a verifiable data item (standard numeric/countable field)
 */
function renderVerifiableItem($item_number, $label, $dept_value, $auto_score, $max_score, $docs = [], $is_locked = false) {
    global $expert_review, $expert_scores, $expert_item_scores, $is_readonly, $is_chairman_view, $is_department_view;
    
    $item_id = "item_" . preg_replace('/[^a-z0-9_]/', '_', strtolower($label));
    $readonly_mode = isset($is_readonly) && $is_readonly || isset($is_chairman_view) && $is_chairman_view;
    $hide_expert_columns = isset($is_department_view) && $is_department_view;
    
    // Get saved expert score for this item from JSON (if stored)
    $saved_expert_score = 0;
    if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores[$item_id])) {
        $saved_expert_score = (float)$expert_item_scores[$item_id];
    }
    
    // Use saved expert score if available, otherwise 0
    $initial_input_value = $saved_expert_score;
    ?>
    <div class="data-grid" <?php if ($readonly_mode || $hide_expert_columns): ?>style="grid-template-columns: 2fr 1fr 1fr;"<?php endif; ?>>
        <div class="field-label">
            <strong><?php echo $item_number; ?>.</strong> <?php echo htmlspecialchars($label); ?>
            <?php if (!empty($docs)): 
                // Group documents by exact document_title from database (as stored in dept_login)
                $grouped_docs_by_title = [];
                foreach ($docs as $doc) {
                    $doc_title = trim($doc['document_title'] ?? 'Other Documents');
                    if (empty($doc_title)) {
                        $doc_title = 'Other Documents';
                    }
                    if (!isset($grouped_docs_by_title[$doc_title])) {
                        $grouped_docs_by_title[$doc_title] = [];
                    }
                    $grouped_docs_by_title[$doc_title][] = $doc;
                }
            ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_docs" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_docs">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div class="collapse mt-2" id="<?php echo $item_id; ?>_docs">
                        <div class="card card-body bg-light">
                            <?php foreach ($grouped_docs_by_title as $doc_heading => $documents): ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong class="d-block mb-2"><?php echo htmlspecialchars($doc_heading); ?></strong>
                                    <div class="text-muted mb-2"><small>Document uploaded</small></div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($documents as $doc): 
                                            // Build proper web-accessible file path
                                            $doc_path = $doc['file_path'] ?? '';
                                            if (strpos($doc_path, '../') === 0) {
                                                $web_path = $doc_path;
                                            } elseif (strpos($doc_path, 'uploads/') === 0) {
                                                $web_path = '../' . $doc_path;
                                            } else {
                                                $web_path = '../' . ltrim($doc_path, '/');
                                            }
                                            
                                            // Verify file exists
                                            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                            $file_exists = file_exists($physical_path);
                                            $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
                                            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
                                        ?>
                                            <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-outline-primary <?php echo $file_exists ? '' : 'disabled'; ?>"
                                               title="<?php echo $doc_title; ?>"
                                               <?php if (!$file_exists): ?>onclick="alert('Document file not found. Please contact administrator.'); return false;"<?php endif; ?>>
                                                <i class="fas fa-file-pdf"></i> 
                                                <?php echo strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="dept-value">
            <?php renderDeptValueContent($dept_value); ?>
        </div>
        <div class="auto-score">
            <?php echo number_format($auto_score, 2); ?> / <?php echo number_format($max_score, 2); ?>
        </div>
        <?php if (!$readonly_mode && !$hide_expert_columns): ?>
        <div>
            <input type="number" 
                   class="expert-input" 
                   id="<?php echo $item_id; ?>"
                   name="<?php echo $item_id; ?>"
                   min="0" 
                   max="<?php echo $max_score; ?>" 
                   step="0.01"
                   value="<?php echo number_format($initial_input_value, 2); ?>"
                   data-initial-value="<?php echo number_format($initial_input_value, 2); ?>"
                   data-auto-score="<?php echo number_format($auto_score, 2); ?>"
                   onchange="recalculateScores()">
        </div>
        <?php endif; ?>
        <?php if (!$hide_expert_columns): ?>
        <div class="expert-score">
            <?php 
            // For read-only fields (auto-calculated), expert score = auto score (expert can't change it)
            // For editable fields: Use saved expert score if available, otherwise show what's in input
            // In readonly mode (Chairman view), show saved expert score if available, otherwise auto_score
            if ($readonly_mode) {
                // In readonly mode, show saved expert score if available, otherwise auto_score
                $display_expert_score = $saved_expert_score > 0 ? $saved_expert_score : $auto_score;
            } else {
                // In edit mode, show the input value (which is initialized from saved score)
                $display_expert_score = $initial_input_value;
            }
            ?>
            <span id="<?php echo $item_id; ?>_score"><?php echo number_format($display_expert_score, 2); ?></span> / <?php echo $max_score; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render a narrative/descriptive item (expert evaluation)
 */
function renderNarrativeItem($item_number, $label, $dept_text, $max_score, $docs = [], $is_locked = false) {
    global $expert_review, $expert_narrative_scores, $is_readonly, $is_chairman_view;
    // Use item_number for consistent ID generation (matches JavaScript collection)
    $item_id = "narrative_" . $item_number;
    // CRITICAL: readonly_mode should only be true if we're in chairman view
    // For expert view, readonly_mode should be false so expert can enter scores even if review is locked
    // is_locked only prevents saving, not entering scores
    $readonly_mode = isset($is_chairman_view) && $is_chairman_view;
    
    // Calculate auto score based on department's response
    // If response exists and has content, give auto score based on response quality
    $auto_score = 0.00;
    if (isset($dept_text) && $dept_text !== null) {
        $clean_text = trim($dept_text);
        // Check if response has actual content (not empty, not "-")
        if ($clean_text !== '' && $clean_text !== '-' && $clean_text !== 'Not provided') {
            // Calculate auto score based on response length and quality
            $text_length = strlen($clean_text);
            // Minimum threshold: at least 10 characters for any score
            if ($text_length >= 10) {
                // Base score: 30% of max for minimum response (10-50 chars)
                // Progressive score: up to 70% of max for substantial responses (200+ chars)
                if ($text_length < 50) {
                    $auto_score = $max_score * 0.30; // 30% for short responses
                } elseif ($text_length < 100) {
                    $auto_score = $max_score * 0.50; // 50% for medium responses
                } elseif ($text_length < 200) {
                    $auto_score = $max_score * 0.60; // 60% for good responses
                } else {
                    $auto_score = $max_score * 0.70; // 70% for substantial responses
                }
                // Cap at max_score
                $auto_score = min($auto_score, $max_score);
            }
        }
    }
    
    // Debug: Log readonly mode status
    error_log("[renderNarrativeItem] Item $item_number - readonly_mode: " . ($readonly_mode ? 'TRUE' : 'FALSE') . ", is_readonly: " . (isset($is_readonly) && $is_readonly ? 'TRUE' : 'FALSE') . ", is_chairman_view: " . (isset($is_chairman_view) && $is_chairman_view ? 'TRUE' : 'FALSE') . ", is_locked: " . ($is_locked ? 'TRUE' : 'FALSE'));
    
    // Get saved narrative score and remarks from JSON
    $saved_narrative_score = 0;
    $saved_narrative_remarks = '';
    if (isset($expert_narrative_scores) && is_array($expert_narrative_scores)) {
        // Try both item_number format and label-based format for backward compatibility
        $item_id_by_number = "narrative_" . $item_number;
        $item_id_by_label = "narrative_" . preg_replace('/[^a-z0-9_]/', '_', strtolower($label));
        
        // Check item_number format first (new format)
        if (isset($expert_narrative_scores[$item_id_by_number])) {
            $narrative_data = $expert_narrative_scores[$item_id_by_number];
        } elseif (isset($expert_narrative_scores[$item_id_by_label])) {
            // Fallback to label-based format (old format)
            $narrative_data = $expert_narrative_scores[$item_id_by_label];
        } else {
            $narrative_data = null;
        }
        
        if ($narrative_data !== null) {
            if (is_array($narrative_data)) {
                $saved_narrative_score = (float)($narrative_data['score'] ?? 0);
                $saved_narrative_remarks = (string)($narrative_data['remarks'] ?? '');
            } else {
                $saved_narrative_score = (float)$narrative_data;
            }
        }
    }
    
    // Debug logging
    if ($readonly_mode) {
        error_log("[Narrative Item $item_number] Item ID: $item_id, Saved Score: $saved_narrative_score, Has Remarks: " . (!empty($saved_narrative_remarks) ? 'YES' : 'NO'));
        if (isset($expert_narrative_scores) && is_array($expert_narrative_scores)) {
            error_log("[Narrative Item $item_number] Available keys: " . implode(', ', array_keys($expert_narrative_scores)));
        }
    }
    ?>
    <!-- Narrative items use standard data-grid layout for consistency -->
    <!-- Ensure grid columns match header: Data Point | Dept Value | Auto Score | Expert Input | Expert Score -->
    <div class="data-grid" data-item="<?php echo $item_number; ?>">
        <div class="field-label">
            <strong><?php echo $item_number; ?>.</strong> <?php echo htmlspecialchars($label); ?>
            <span class="badge bg-info ms-2">Max: <?php echo $max_score; ?> marks</span>
            <?php if (!empty($docs)): 
                // Group documents by exact document_title from database (as stored in dept_login)
                $grouped_docs_by_title = [];
                foreach ($docs as $doc) {
                    $doc_title = trim($doc['document_title'] ?? 'Other Documents');
                    if (empty($doc_title)) {
                        $doc_title = 'Other Documents';
                    }
                    if (!isset($grouped_docs_by_title[$doc_title])) {
                        $grouped_docs_by_title[$doc_title] = [];
                    }
                    $grouped_docs_by_title[$doc_title][] = $doc;
                }
            ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_docs" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_docs">
                        <i class="fas fa-file-pdf"></i> View Supporting Docs
                    </button>
                    <div class="collapse mt-2" id="<?php echo $item_id; ?>_docs">
                        <div class="card card-body bg-light">
                            <?php foreach ($grouped_docs_by_title as $doc_heading => $documents): ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong class="d-block mb-2"><?php echo htmlspecialchars($doc_heading); ?></strong>
                                    <div class="text-muted mb-2"><small>Document uploaded</small></div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($documents as $doc): 
                                            // Build proper web-accessible file path
                                            $doc_path = $doc['file_path'] ?? '';
                                            if (strpos($doc_path, '../') === 0) {
                                                $web_path = $doc_path;
                                            } elseif (strpos($doc_path, 'uploads/') === 0) {
                                                $web_path = '../' . $doc_path;
                                            } else {
                                                $web_path = '../' . ltrim($doc_path, '/');
                                            }
                                            
                                            // Verify file exists
                                            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                            $file_exists = file_exists($physical_path);
                                            $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
                                            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
                                        ?>
                                            <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                               target="_blank" 
                                               class="btn btn-sm btn-outline-primary <?php echo $file_exists ? '' : 'disabled'; ?>"
                                               title="<?php echo $doc_title; ?>"
                                               <?php if (!$file_exists): ?>onclick="alert('Document file not found. Please contact administrator.'); return false;"<?php endif; ?>>
                                                <i class="fas fa-file-pdf"></i> 
                                                <?php echo strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="dept-value narrative-dept-response">
            <div class="narrative-response-header">
                <strong>Department's Response:</strong>
            </div>
            <div class="narrative-response-content">
                <?php 
                // CRITICAL: Check if dept_text is actually set and not empty
                // Don't use ?? operator here as it might mask the issue
                $response_text = '';
                if (isset($dept_text) && $dept_text !== null) {
                    $response_text = $dept_text;
                } else {
                    $response_text = 'Not provided';
                }
                
                // Debug: Log what we received
                error_log("[renderNarrativeItem] Item $item_number - Received dept_text: " . (isset($dept_text) ? ('"' . substr($dept_text, 0, 100) . '" (length: ' . strlen($dept_text) . ', type: ' . gettype($dept_text) . ')') : 'NULL'));
                error_log("[renderNarrativeItem] Item $item_number - response_text after processing: " . ('"' . substr($response_text, 0, 100) . '" (length: ' . strlen($response_text) . ')'));
                
                // Filter out "-" and empty values - treat them as empty
                // BUT: Only filter if it's actually "-", not if it's actual text
                $clean_text = trim($response_text);
                // CRITICAL: Check if text is actually empty or just "-"
                // Don't filter out actual content
                if ($clean_text === '' || $clean_text === '-' || $clean_text === 'Not provided' || $clean_text === null) {
                    echo '<span class="text-muted">No response provided</span>';
                } else {
                    // Display the actual text - don't filter out real content
                    // Preserve line breaks and escape HTML
                    echo nl2br(htmlspecialchars($clean_text, ENT_QUOTES, 'UTF-8'));
                }
                ?>
            </div>
        </div>
        <div class="auto-score" data-auto-score="<?php echo number_format($auto_score, 2); ?>">
            <?php echo number_format($auto_score, 2); ?> / <?php echo number_format($max_score, 2); ?>
        </div>
        <?php if (!$readonly_mode): ?>
        <div>
            <input type="number" 
                   class="expert-input" 
                   id="<?php echo $item_id; ?>_score"
                   name="<?php echo $item_id; ?>_score"
                   min="0" 
                   max="<?php echo $max_score; ?>" 
                   step="0.5"
                   placeholder="Enter score"
                   value="<?php echo number_format($saved_narrative_score, 2); ?>"
                   data-initial-value="<?php echo number_format($saved_narrative_score, 2); ?>"
                   data-auto-score="<?php echo number_format($auto_score, 2); ?>"
                   onchange="recalculateScores()">
        </div>
        <?php endif; ?>
        <div class="expert-score">
            <span id="<?php echo $item_id; ?>_score_display"><?php echo number_format($saved_narrative_score, 2); ?></span> / <?php echo $max_score; ?>
        </div>
    </div>
    <!-- Expert Remarks (shown below the grid for narrative items) -->
    <?php if (!$readonly_mode): ?>
    <script>
    // Update narrative score display when input changes
    (function() {
        const scoreInput = document.getElementById('<?php echo $item_id; ?>_score');
        const scoreDisplay = document.getElementById('<?php echo $item_id; ?>_score_display');
        if (scoreInput && scoreDisplay) {
            scoreInput.addEventListener('input', function() {
                const value = parseFloat(this.value) || 0;
                const maxScore = parseFloat(this.getAttribute('max')) || <?php echo $max_score; ?>;
                const displayValue = Math.min(Math.max(value, 0), maxScore);
                scoreDisplay.textContent = displayValue.toFixed(2);
            });
        }
    })();
    </script>
    <?php endif; ?>
    <div class="mt-2 mb-3" style="padding: 0 1rem;">
        <label class="form-label"><strong>Expert Remarks:</strong></label>
        <?php if ($readonly_mode): ?>
            <div class="read-only-remarks" style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; min-height: 60px; color: #1f2937; white-space: pre-wrap; word-wrap: break-word; user-select: text; -webkit-user-modify: read-only; -moz-user-modify: read-only; user-modify: read-only; contenteditable="false" spellcheck="false">
                <?php 
                echo $saved_narrative_remarks ? nl2br(htmlspecialchars($saved_narrative_remarks)) : '<span class="text-muted">No remarks provided</span>';
                ?>
            </div>
        <?php else: ?>
            <textarea class="narrative-field" 
                      id="<?php echo $item_id; ?>_remarks"
                      name="<?php echo $item_id; ?>_remarks"
                      placeholder="Enter your evaluation remarks..."
                      onchange="if(typeof recalculateScores === 'function') recalculateScores();"><?php 
                echo htmlspecialchars($saved_narrative_remarks);
            ?></textarea>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render Infrastructure Item (4 separate areas, expert evaluated)
 * Section III, Item 8: Infrastructure strengthening in 4 areas
 * Each area: Max 2.5 marks, total: 10 marks
 */
function renderInfrastructureItem($item_number, $sec3_data, $docs = [], $is_locked = false) {
    global $expert_narrative_scores, $is_readonly, $is_chairman_view, $is_department_view;
    
    $readonly_mode = isset($is_chairman_view) && $is_chairman_view;
    $hide_expert_columns = isset($is_department_view) && $is_department_view;
    
    // Get the 4 infrastructure descriptions from department
    $infra_infrastructural = trim($sec3_data['infrastructure_infrastructural'] ?? '');
    $infra_it_digital = trim($sec3_data['infrastructure_it_digital'] ?? '');
    $infra_library = trim($sec3_data['infrastructure_library'] ?? '');
    $infra_laboratory = trim($sec3_data['infrastructure_laboratory'] ?? '');
    
    // Calculate auto-scores based on response quality (max 2.5 per area)
    $area_texts = [
        'infrastructural' => $infra_infrastructural,
        'it_digital' => $infra_it_digital,
        'library' => $infra_library,
        'laboratory' => $infra_laboratory
    ];
    
    $auto_scores = [];
    foreach ($area_texts as $area_key => $text) {
        $auto_score = 0.0;
        if (!empty($text) && $text !== '-' && $text !== 'Not provided') {
            $text_length = strlen($text);
            // Auto-scoring based on response quality (max 2.5 per area)
            if ($text_length >= 10) {
                if ($text_length < 50) {
                    $auto_score = 0.75; // 30% of 2.5 for short responses
                } elseif ($text_length < 100) {
                    $auto_score = 1.25; // 50% of 2.5 for medium responses
                } elseif ($text_length < 200) {
                    $auto_score = 1.50; // 60% of 2.5 for good responses
                } else {
                    $auto_score = 1.75; // 70% of 2.5 for substantial responses
                }
            }
        }
        $auto_scores[$area_key] = $auto_score;
    }
    
    $total_auto_score = array_sum($auto_scores);
    
    // Get saved expert scores for each area
    $saved_scores = [
        'infrastructural' => 0,
        'it_digital' => 0,
        'library' => 0,
        'laboratory' => 0
    ];
    $saved_remarks = [
        'infrastructural' => '',
        'it_digital' => '',
        'library' => '',
        'laboratory' => ''
    ];
    
    if (isset($expert_narrative_scores) && is_array($expert_narrative_scores)) {
        foreach (['infrastructural', 'it_digital', 'library', 'laboratory'] as $area) {
            $item_id = "infrastructure_" . $area;
            if (isset($expert_narrative_scores[$item_id])) {
                $area_data = $expert_narrative_scores[$item_id];
                if (is_array($area_data)) {
                    $saved_scores[$area] = (float)($area_data['score'] ?? 0);
                    $saved_remarks[$area] = (string)($area_data['remarks'] ?? '');
                } else {
                    $saved_scores[$area] = (float)$area_data;
                }
            }
        }
    }
    
    $total_expert_score = array_sum($saved_scores);
    
    ?>
    <div class="infrastructure-item mb-4">
        <div class="field-label mb-3" style="font-size: 1rem;">
            <strong><?php echo $item_number; ?>.</strong> Efforts taken for Strengthening/Augmentation of Departmental Facilities
            <span class="badge bg-info ms-2" style="font-size: 0.85rem;">Max 2.5 marks each × 4 areas = 10 marks total</span>
            <?php if (!empty($docs)): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#infrastructure_docs" aria-expanded="false" aria-controls="infrastructure_docs" style="font-size: 0.9rem;">
                        <i class="fas fa-file-pdf"></i> View Supporting Docs
                    </button>
                    <div class="collapse mt-2" id="infrastructure_docs">
                        <div class="card card-body bg-light">
                            <?php foreach ($docs as $doc): 
                                $doc_path = $doc['file_path'] ?? '';
                                if (strpos($doc_path, '../') === 0) {
                                    $web_path = $doc_path;
                                } elseif (strpos($doc_path, 'uploads/') === 0) {
                                    $web_path = '../' . $doc_path;
                                } else {
                                    $web_path = '../' . ltrim($doc_path, '/');
                                }
                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                $file_exists = file_exists($physical_path);
                                $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
                            ?>
                                <a href="<?php echo htmlspecialchars($web_path); ?>" 
                                   target="_blank" 
                                   class="btn btn-sm btn-outline-primary <?php echo $file_exists ? '' : 'disabled'; ?> mb-2"
                                   <?php if (!$file_exists): ?>onclick="alert('Document file not found.'); return false;"<?php endif; ?>>
                                    <i class="fas fa-file-pdf"></i> <?php echo $doc_name; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 4 Infrastructure Areas -->
        <?php
        $areas = [
            'infrastructural' => ['label' => '1. Departmental Infrastructural Facilities', 'text' => $infra_infrastructural],
            'it_digital' => ['label' => '2. Computational/IT/Digital Facilities', 'text' => $infra_it_digital],
            'library' => ['label' => '3. Library Facilities', 'text' => $infra_library],
            'laboratory' => ['label' => '4. Laboratory Facilities', 'text' => $infra_laboratory]
        ];
        
        foreach ($areas as $area_key => $area_info):
            $item_id = "infrastructure_" . $area_key;
            $area_text = $area_info['text'];
            $has_text = !empty($area_text) && $area_text !== '-';
            $area_auto_score = $auto_scores[$area_key];
        ?>
            <div class="infrastructure-area border rounded p-3 mb-3" style="background-color: #f8f9fa;">
                <div class="row">
                    <div class="<?php echo $hide_expert_columns ? 'col-md-8' : 'col-md-6'; ?>">
                        <h6 class="text-primary" style="font-size: 1rem;"><?php echo htmlspecialchars($area_info['label']); ?></h6>
                        <div class="dept-response bg-light p-2 rounded mb-2" style="min-height: 60px; font-size: 0.95rem;">
                            <?php if ($has_text): ?>
                                <?php echo nl2br(htmlspecialchars($area_text)); ?>
                            <?php else: ?>
                                <span class="text-muted">No description provided</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="<?php echo $hide_expert_columns ? 'col-md-4' : 'col-md-3'; ?>">
                        <label class="form-label" style="font-size: 0.95rem;"><strong>Dept Auto Score:</strong></label>
                        <div class="auto-score-display p-2 bg-info bg-opacity-10 border border-info rounded text-center" style="font-size: 1rem;">
                            <strong><?php echo number_format($area_auto_score, 2); ?> / 2.50</strong>
                        </div>
                        <small class="text-muted d-block mt-1" style="font-size: 0.8rem;">Based on response quality</small>
                    </div>
                    <?php if (!$hide_expert_columns): ?>
                    <div class="col-md-3">
                        <label class="form-label" style="font-size: 0.95rem;"><strong>Expert Score:</strong></label>
                        <?php if (!$readonly_mode): ?>
                            <input type="number" 
                                   class="form-control expert-input infrastructure-score-input" 
                                   id="<?php echo $item_id; ?>_score"
                                   name="<?php echo $item_id; ?>_score"
                                   min="0" 
                                   max="2.5" 
                                   step="0.1"
                                   value="<?php echo number_format($saved_scores[$area_key], 2); ?>"
                                   data-auto-score="<?php echo number_format($area_auto_score, 2); ?>"
                                   onchange="updateInfrastructureTotal(); recalculateScores();"
                                   placeholder="<?php echo number_format($area_auto_score, 2); ?>"
                                   style="font-size: 1rem;">
                            <div class="mt-2">
                                <small class="text-muted" style="font-size: 0.85rem;">Score: <span id="<?php echo $item_id; ?>_display"><?php echo number_format($saved_scores[$area_key], 2); ?></span> / 2.50</small>
                            </div>
                        <?php else: ?>
                            <div class="read-only-score p-2 bg-light border rounded text-center" style="font-size: 1rem;">
                                <?php echo number_format($saved_scores[$area_key], 2); ?> / 2.50
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Expert Remarks for this area -->
                <?php if (!$hide_expert_columns): ?>
                <div class="mt-2">
                    <label class="form-label" style="font-size: 0.95rem;"><strong>Expert Remarks:</strong></label>
                    <?php if ($readonly_mode): ?>
                        <div class="read-only-remarks bg-light border rounded p-2" style="min-height: 50px; font-size: 0.95rem;">
                            <?php echo $saved_remarks[$area_key] ? nl2br(htmlspecialchars($saved_remarks[$area_key])) : '<span class="text-muted">No remarks</span>'; ?>
                        </div>
                    <?php else: ?>
                        <textarea class="form-control" 
                                  id="<?php echo $item_id; ?>_remarks"
                                  name="<?php echo $item_id; ?>_remarks"
                                  rows="2"
                                  placeholder="Enter remarks for this area..."
                                  style="font-size: 0.95rem;"><?php echo htmlspecialchars($saved_remarks[$area_key]); ?></textarea>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <!-- Total Score Display -->
        <div class="row mt-3">
            <div class="<?php echo $hide_expert_columns ? 'col-md-12' : 'col-md-6'; ?>">
                <div class="alert alert-info mb-0" style="font-size: 1rem;">
                    <strong>Dept Auto Score Total:</strong> 
                    <?php echo number_format($total_auto_score, 2); ?> / 10.00
                </div>
            </div>
            <?php if (!$hide_expert_columns): ?>
            <div class="col-md-6">
                <div class="alert alert-success mb-0" style="font-size: 1rem;">
                    <strong>Expert Score Total:</strong> 
                    <span id="infrastructure_total_score"><?php echo number_format($total_expert_score, 2); ?></span> / 10.00
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    function updateInfrastructureTotal() {
        let total = 0;
        document.querySelectorAll('.infrastructure-score-input').forEach(input => {
            const value = parseFloat(input.value) || 0;
            const max = parseFloat(input.getAttribute('max')) || 2.5;
            const clamped = Math.min(Math.max(value, 0), max);
            total += clamped;
            
            // Update individual display
            const displayId = input.id.replace('_score', '_display');
            const displayElem = document.getElementById(displayId);
            if (displayElem) {
                displayElem.textContent = clamped.toFixed(2);
            }
        });
        
        const totalElem = document.getElementById('infrastructure_total_score');
        if (totalElem) {
            totalElem.textContent = total.toFixed(2);
        }
    }
    
    // Initialize on load
    document.addEventListener('DOMContentLoaded', updateInfrastructureTotal);
    </script>
    <?php
}

/**
 * Render JSON array items (publications, projects, etc.)
 * Shows all entries first, then all supporting documents grouped by heading (like Item 13 - Startups)
 * @param callable|null $get_entry_docs_callback Optional callback function(item, item_number) that returns documents for a specific entry
 * @param array|null $grouped_docs_array Optional array of document headings => documents (like startups format)
 */
function renderJSONArrayItems($item_number, $label, $json_data, $auto_score, $max_score, $docs = [], $is_locked = false, $get_entry_docs_callback = null, $grouped_docs_array = null) {
    global $expert_review, $expert_scores, $grouped_docs, $expert_item_scores, $is_readonly, $is_chairman_view;
    $items = json_decode($json_data, true);
    $count = is_array($items) ? count($items) : 0;
    $item_id = "json_" . preg_replace('/[^a-z0-9_]/', '_', strtolower($label));
    $readonly_mode = isset($is_readonly) && $is_readonly || isset($is_chairman_view) && $is_chairman_view;
    
    // Get saved expert score for this item from expert_item_scores JSON
    $saved_expert_score = 0;
    if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores[$item_id])) {
        $saved_expert_score = (float)$expert_item_scores[$item_id];
    } elseif ($expert_review && isset($expert_scores["item_$item_number"])) {
        $saved_expert_score = (float)($expert_scores["item_$item_number"] ?? 0);
    }
    
    // Initial input value: Use saved score if available, otherwise 0
    $initial_input_value = $saved_expert_score;
    
    // Helper function to get entry display name/value
    $getEntryDisplay = function($item) {
        // Try common name/title fields
        $name = $item['name'] ?? $item['title'] ?? $item['student_name'] ?? $item['teacher_name'] ?? 
                $item['Initiative'] ?? $item['Platform'] ?? $item['project_title'] ?? '';
        
        // If no name field, use first non-empty value
        if (empty($name)) {
            foreach ($item as $key => $val) {
                if (!empty($val) && !in_array(strtolower($key), ['id', 'dept_id', 'a_year', 'serial_number'])) {
                    $name = $val;
                    break;
                }
            }
        }
        
        // Build value string from other fields
        $value_parts = [];
        foreach ($item as $key => $val) {
            if (!empty($val) && !in_array(strtolower($key), ['id', 'dept_id', 'a_year', 'serial_number', 'name', 'title', 'student_name', 'teacher_name'])) {
                $key_label = ucfirst(str_replace('_', ' ', $key));
                $value_parts[] = "$key_label: " . htmlspecialchars($val);
            }
        }
        $value_str = !empty($value_parts) ? implode(', ', $value_parts) : '';
        
        return ['name' => htmlspecialchars($name), 'value' => $value_str];
    };
    
    // Helper function to render document links (for inline display)
    $renderDocLinks = function($documents) {
        if (empty($documents)) {
            return '<small class="text-muted">No supporting documents found.</small>';
        }
        
        $html = '<div class="mt-2">';
        $html .= '<small class="text-muted">Supporting Documents (' . count($documents) . '):</small>';
        $html .= '<div class="d-flex flex-wrap gap-2 mt-1">';
        
        foreach ($documents as $doc) {
            $doc_path = $doc['file_path'] ?? '';
            if (strpos($doc_path, '../') === 0) {
                $web_path = $doc_path;
            } elseif (strpos($doc_path, 'uploads/') === 0) {
                $web_path = '../' . $doc_path;
            } else {
                $web_path = '../' . ltrim($doc_path, '/');
            }
            
            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
            $file_exists = file_exists($physical_path);
            $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
            
            $html .= '<a href="' . htmlspecialchars($web_path) . '" ';
            $html .= 'target="_blank" ';
            $html .= 'class="btn btn-sm btn-outline-primary ' . ($file_exists ? '' : 'disabled') . '" ';
            $html .= 'title="' . $doc_title . '"';
            if (!$file_exists) {
                $html .= ' onclick="alert(\'Document file not found. Please contact administrator.\'); return false;"';
            }
            $html .= '>';
            $html .= '<i class="fas fa-file-pdf"></i> ';
            $html .= (strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name);
            $html .= '</a>';
        }
        
        $html .= '</div></div>';
        return $html;
    };
    
    // Helper function to render document links in collapsible format
    $renderDocLinksCollapsible = function($documents, $item_id) {
        if (empty($documents)) {
            return '<small class="text-muted">No file chosen</small>';
        }
        
        $html = '<div class="d-flex flex-wrap gap-2">';
        foreach ($documents as $doc) {
            $doc_path = $doc['file_path'] ?? '';
            if (strpos($doc_path, '../') === 0) {
                $web_path = $doc_path;
            } elseif (strpos($doc_path, 'uploads/') === 0) {
                $web_path = '../' . $doc_path;
            } else {
                $web_path = '../' . ltrim($doc_path, '/');
            }
            
            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
            $file_exists = file_exists($physical_path);
            $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
            
            $html .= '<a href="' . htmlspecialchars($web_path) . '" ';
            $html .= 'target="_blank" ';
            $html .= 'class="btn btn-sm btn-outline-primary ' . ($file_exists ? '' : 'disabled') . '" ';
            $html .= 'title="' . $doc_title . '"';
            if (!$file_exists) {
                $html .= ' onclick="alert(\'Document file not found. Please contact administrator.\'); return false;"';
            }
            $html .= '>';
            $html .= '<i class="fas fa-file-pdf"></i> ';
            $html .= (strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name);
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    };
    ?>
    <div class="data-grid" <?php if ($readonly_mode): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?> data-item="<?php echo $item_number; ?>">
        <div class="field-label">
            <strong><?php echo $item_number; ?>. <?php echo htmlspecialchars($label); ?></strong>
            
            <!-- Individual entries with their documents -->
            <?php if ($count > 0): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_entries" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_entries">
                        <i class="fas fa-list"></i> View Entries (<?php echo $count; ?>)
                    </button>
                    <div class="collapse mt-2" id="<?php echo $item_id; ?>_entries">
                        <div class="card card-body bg-light">
                            <?php foreach ($items as $idx => $item): ?>
                                <?php
                                $entry_display = $getEntryDisplay($item);
                                $entry_name = $entry_display['name'];
                                $entry_value = $entry_display['value'];
                                
                                // Get entry-specific documents if callback is provided, otherwise use general docs
                                $entry_docs = $docs;
                                if ($get_entry_docs_callback && is_callable($get_entry_docs_callback)) {
                                    $entry_specific_docs = call_user_func($get_entry_docs_callback, $item, $item_number);
                                    if (!empty($entry_specific_docs)) {
                                        $entry_docs = $entry_specific_docs;
                                    }
                                }
                                ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong><?php echo ($idx + 1); ?>. <?php echo $entry_name ?: 'Entry ' . ($idx + 1); ?></strong>
                                    <?php if (!empty($entry_value)): ?>
                                        <div class="mt-1">
                                            <span class="text-muted"><?php echo $entry_value; ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- All supporting documents grouped by heading (like Item 13 - Startups format) -->
            <?php 
            // Collect ALL documents from all entries (if callback provided) or use main $docs
            $all_documents = [];
            
            // If callback is provided, collect documents from all entries
            if ($get_entry_docs_callback && is_callable($get_entry_docs_callback) && $count > 0) {
                foreach ($items as $item) {
                    $entry_docs = call_user_func($get_entry_docs_callback, $item, $item_number);
                    if (!empty($entry_docs)) {
                        $all_documents = array_merge($all_documents, $entry_docs);
                    }
                }
            }
            
            // Also add main $docs if provided
            if (!empty($docs)) {
                $all_documents = array_merge($all_documents, $docs);
            }
            
            // If grouped_docs_array is provided, use that format (like startups)
            // Otherwise, group documents by their exact document_title from database
            if ($grouped_docs_array !== null && is_array($grouped_docs_array)) {
                // Use provided grouped documents array
                $docs_to_display = $grouped_docs_array;
            } else {
                // Group documents by their exact document_title (heading) as stored in database
                // Use the exact title from dept_login - don't modify it
                $docs_to_display = [];
                foreach ($all_documents as $doc) {
                    $doc_title = $doc['document_title'] ?? 'Other Documents';
                    // Use exact title as stored - don't remove "Documentation" or modify it
                    $heading = trim($doc_title);
                    if (empty($heading)) {
                        $heading = 'Other Documents';
                    }
                    if (!isset($docs_to_display[$heading])) {
                        $docs_to_display[$heading] = [];
                    }
                    $docs_to_display[$heading][] = $doc;
                }
            }
            
            // Only show documents section if there are documents
            if (!empty($docs_to_display)): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_docs" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_docs">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div class="collapse mt-2" id="<?php echo $item_id; ?>_docs">
                        <div class="card card-body bg-light">
                            <?php foreach ($docs_to_display as $doc_heading => $documents): 
                                $doc_count = count($documents);
                            ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong class="d-block mb-2"><?php echo htmlspecialchars($doc_heading); ?></strong>
                                    <?php if ($doc_count > 0): ?>
                                        <div class="text-muted mb-2"><small>Document uploaded</small></div>
                                        <?php 
                                        $renderGroupedDocLinks = function($documents) {
                                            if (empty($documents)) {
                                                return '<small class="text-muted">No file chosen</small>';
                                            }
                                            $html = '<div class="d-flex flex-wrap gap-2">';
                                            foreach ($documents as $doc) {
                                                $doc_path = $doc['file_path'] ?? '';
                                                if (strpos($doc_path, '../') === 0) {
                                                    $web_path = $doc_path;
                                                } elseif (strpos($doc_path, 'uploads/') === 0) {
                                                    $web_path = '../' . $doc_path;
                                                } else {
                                                    $web_path = '../' . ltrim($doc_path, '/');
                                                }
                                                $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                                $file_exists = file_exists($physical_path);
                                                $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
                                                $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
                                                $html .= '<a href="' . htmlspecialchars($web_path) . '" target="_blank" class="btn btn-sm btn-outline-primary ' . ($file_exists ? '' : 'disabled') . '" title="' . $doc_title . '"';
                                                if (!$file_exists) {
                                                    $html .= ' onclick="alert(\'Document file not found. Please contact administrator.\'); return false;"';
                                                }
                                                $html .= '><i class="fas fa-file-pdf"></i> ' . (strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name) . '</a>';
                                            }
                                            $html .= '</div>';
                                            return $html;
                                        };
                                        echo $renderGroupedDocLinks($documents); 
                                        ?>
                                    <?php else: ?>
                                        <small class="text-muted">No file chosen</small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Fallback: Show documents at item level if no entries -->
            <?php if ($count == 0 && !empty($docs) && empty($docs_to_display)): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_docs" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_docs">
                        <i class="fas fa-info-circle"></i> View Details
                    </button>
                    <div class="collapse mt-2" id="<?php echo $item_id; ?>_docs">
                        <div class="card card-body bg-light">
                            <div class="text-muted mb-2"><small>Document uploaded</small></div>
                            <?php 
                            $renderItemDocLinks = function($documents) {
                                if (empty($documents)) {
                                    return '<small class="text-muted">No file chosen</small>';
                                }
                                $html = '<div class="d-flex flex-wrap gap-2">';
                                foreach ($documents as $doc) {
                                    $doc_path = $doc['file_path'] ?? '';
                                    if (strpos($doc_path, '../') === 0) {
                                        $web_path = $doc_path;
                                    } elseif (strpos($doc_path, 'uploads/') === 0) {
                                        $web_path = '../' . $doc_path;
                                    } else {
                                        $web_path = '../' . ltrim($doc_path, '/');
                                    }
                                    $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
                                    $file_exists = file_exists($physical_path);
                                    $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
                                    $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
                                    $html .= '<a href="' . htmlspecialchars($web_path) . '" target="_blank" class="btn btn-sm btn-outline-primary ' . ($file_exists ? '' : 'disabled') . '" title="' . $doc_title . '"';
                                    if (!$file_exists) {
                                        $html .= ' onclick="alert(\'Document file not found. Please contact administrator.\'); return false;"';
                                    }
                                    $html .= '><i class="fas fa-file-pdf"></i> ' . (strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name) . '</a>';
                                }
                                $html .= '</div>';
                                return $html;
                            };
                            echo $renderItemDocLinks($docs); 
                            ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="dept-value">
            Count: <?php echo $count; ?>
        </div>
        
        <div class="auto-score" data-auto-score="<?php echo $auto_score; ?>">
            <?php echo number_format($auto_score, 2); ?> / <?php echo number_format($max_score, 2); ?>
        </div>
        
        <?php if (!$readonly_mode): ?>
        <div>
            <input type="number" 
                   step="0.01" 
                   min="0" 
                   max="<?php echo $max_score; ?>" 
                   class="expert-input" 
                   id="<?php echo $item_id; ?>" 
                   name="expert_item_<?php echo $item_number; ?>"
                   value="<?php echo number_format($initial_input_value, 2); ?>"
                   data-initial-value="<?php echo $initial_input_value; ?>"
                   data-auto-score="<?php echo $auto_score; ?>"
                   <?php echo $is_locked ? 'readonly' : ''; ?>
                   onchange="updateItemScore(<?php echo $item_number; ?>, this.value)">
        </div>
        <?php endif; ?>
        
        <div class="expert-score" id="<?php echo $item_id; ?>_score">
            <?php 
            // In readonly mode, show saved score if available, otherwise auto_score
            if ($readonly_mode) {
                $display_score = $saved_expert_score > 0 ? $saved_expert_score : $auto_score;
            } else {
                $display_score = $initial_input_value;
            }
            echo number_format($display_score, 2); 
            ?> / <?php echo number_format($max_score, 2); ?>
        </div>
    </div>
    
    <?php if (!$readonly_mode): ?>
    <script>
    // Update item score when input changes
    function updateItemScore(itemNum, value) {
        const input = document.getElementById('<?php echo $item_id; ?>');
        if (input) {
            const scoreDisplay = document.getElementById(input.id + '_score');
            if (scoreDisplay) {
                const maxScore = parseFloat(input.getAttribute('max')) || 0;
                const score = Math.min(Math.max(parseFloat(value) || 0, 0), maxScore);
                scoreDisplay.textContent = score.toFixed(2) + ' / ' + maxScore.toFixed(2);
            }
        }
        // Trigger section recalculation
        if (typeof recalculateScores === 'function') {
            recalculateScores();
        }
    }
    </script>
    <?php endif; ?>
    <?php
}

/**
 * Render a program-specific verifiable item (for placement_details with multiple programs)
 * Shows each program's data with its specific supporting documents
 */
function renderProgramSpecificItem($item_number, $label, $programs_data, $total_dept_value, $auto_score, $max_score, $get_docs_callback, $is_locked = false) {
    global $expert_review, $expert_scores, $expert_item_scores, $is_readonly, $is_chairman_view, $grouped_docs;
    
    $item_id = "item_" . preg_replace('/[^a-z0-9_]/', '_', strtolower($label));
    $readonly_mode = isset($is_readonly) && $is_readonly || isset($is_chairman_view) && $is_chairman_view;
    
    // Ensure programs_data is an array
    if (!is_array($programs_data)) {
        $programs_data = [];
    }
    
    // Get saved expert score for this item from JSON (if stored)
    $saved_expert_score = 0;
    if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores[$item_id])) {
        $saved_expert_score = (float)$expert_item_scores[$item_id];
    }
    
    // Initial input value: Use saved score if available, otherwise 0
    $initial_input_value = $saved_expert_score;
    
    ?>
    <div class="data-grid" <?php if ($readonly_mode): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?> data-item="<?php echo $item_number; ?>">
        <div class="field-label">
            <strong><?php echo $item_number; ?>. <?php echo htmlspecialchars($label); ?></strong>
            
            <!-- Program-wise breakdown -->
            <?php if (!empty($programs_data) && is_array($programs_data) && count($programs_data) > 0): ?>
                <div class="mt-2 mb-2">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#programs_<?php echo $item_number; ?>" aria-expanded="false" aria-controls="programs_<?php echo $item_number; ?>">
                        <i class="fas fa-list"></i> View Program Details (<?php echo count($programs_data); ?> program<?php echo count($programs_data) > 1 ? 's' : ''; ?>)
                    </button>
                    <div class="collapse mt-2" id="programs_<?php echo $item_number; ?>">
                        <div class="card card-body bg-light">
                            <?php foreach ($programs_data as $idx => $prog): ?>
                                <?php
                                $prog_code = $prog['actual_program_code'] ?? $prog['PROGRAM_CODE'] ?? '';
                                $prog_name = htmlspecialchars($prog['PROGRAM_NAME'] ?? 'N/A');
                                $prog_value = htmlspecialchars($prog['value'] ?? 'N/A');
                                
                                // Get program-specific documents
                                $prog_docs = [];
                                if (is_callable($get_docs_callback)) {
                                    $prog_docs = $get_docs_callback($prog_code, $prog);
                                }
                                ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong><?php echo ($idx + 1); ?>. <?php echo $prog_name; ?></strong>
                                    <div class="mt-1">
                                        <span class="text-muted">Value:</span> <?php echo $prog_value; ?>
                                    </div>
                                    
                                    <!-- Program-specific documents -->
                                    <?php if (!empty($prog_docs)): ?>
                                        <div class="mt-2">
                                            <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_prog_<?php echo $idx; ?>_docs" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_prog_<?php echo $idx; ?>_docs">
                                                <i class="fas fa-info-circle"></i> View Details
                                            </button>
                                            <div class="collapse mt-2" id="<?php echo $item_id; ?>_prog_<?php echo $idx; ?>_docs">
                                                <div class="card card-body bg-light">
                                                    <div class="text-muted mb-2"><small>Document uploaded</small></div>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <?php foreach ($prog_docs as $doc): ?>
                                                            <?php
                                                            $doc_path = $doc['file_path'] ?? '';
                                                            $doc_title = htmlspecialchars($doc['document_title'] ?? 'Document');
                                                            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
                                                            
                                                            // Convert relative path to absolute if needed
                                                            if (!empty($doc_path) && !file_exists($doc_path) && strpos($doc_path, '../') === false) {
                                                                $doc_path = '../' . $doc_path;
                                                            }
                                                            
                                                            // Check if file exists
                                                            $file_exists = !empty($doc_path) && (file_exists($doc_path) || file_exists('../' . $doc_path));
                                                            ?>
                                                            <a href="<?php echo htmlspecialchars($doc_path); ?>" 
                                                               target="_blank" 
                                                               class="btn btn-sm btn-outline-primary <?php echo $file_exists ? '' : 'disabled'; ?>"
                                                               title="<?php echo $doc_title; ?>">
                                                                <i class="fas fa-file-pdf"></i> <?php echo strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name; ?>
                                                            </a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2">
                                            <small class="text-muted">No file chosen</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
        </div>
        
        <div class="dept-value">
            <?php if (!empty($programs_data)): ?>
                <div>
                    <strong>Total:</strong> <?php echo htmlspecialchars($total_dept_value); ?>
                </div>
            <?php else: ?>
                <?php echo htmlspecialchars($total_dept_value); ?>
            <?php endif; ?>
        </div>
        
        <div class="auto-score" data-auto-score="<?php echo $auto_score; ?>">
            <?php echo number_format($auto_score, 2); ?> / <?php echo number_format($max_score, 2); ?>
        </div>
        
        <?php if (!$readonly_mode): ?>
        <div>
            <input type="number" 
                   step="0.01" 
                   min="0" 
                   max="<?php echo $max_score; ?>" 
                   class="expert-input" 
                   id="<?php echo $item_id; ?>" 
                   name="expert_item_<?php echo $item_number; ?>"
                   value="<?php echo number_format($initial_input_value, 2); ?>"
                   data-initial-value="<?php echo $initial_input_value; ?>"
                   data-auto-score="<?php echo $auto_score; ?>"
                   <?php echo $is_locked ? 'readonly' : ''; ?>
                   onchange="updateItemScore(<?php echo $item_number; ?>, this.value)">
        </div>
        <?php endif; ?>
        
        <div class="expert-score" id="<?php echo $item_id; ?>_score">
            <?php 
            // In readonly mode, show saved score if available, otherwise auto_score
            if ($readonly_mode) {
                $display_score = $saved_expert_score > 0 ? $saved_expert_score : $auto_score;
            } else {
                $display_score = $initial_input_value;
            }
            echo number_format($display_score, 2); 
            ?> / <?php echo number_format($max_score, 2); ?>
        </div>
    </div>
    
    <?php if (!$readonly_mode): ?>
    <script>
    // Update item score when input changes
    function updateItemScore(itemNum, value) {
        const input = document.getElementById('item_' + itemNum.toString().replace(/[^a-z0-9_]/g, '_'));
        if (input) {
            const scoreDisplay = document.getElementById(input.id + '_score');
            if (scoreDisplay) {
                const maxScore = parseFloat(input.getAttribute('max')) || 0;
                const score = Math.min(Math.max(parseFloat(value) || 0, 0), maxScore);
                scoreDisplay.textContent = score.toFixed(2) + ' / ' + maxScore.toFixed(2);
            }
        }
        // Trigger section recalculation
        if (typeof recalculateSection4 === 'function') {
            recalculateSection4();
        }
    }
    </script>
    <?php endif; ?>
    <?php
}

/**
 * Render startups with all data first, then all supporting documents grouped by heading
 * Special format for Item 13 (Startups)
 */
function renderStartupsWithGroupedDocuments($item_number, $label, $json_data, $auto_score, $max_score, $grouped_docs_array, $is_locked = false) {
    global $expert_review, $expert_scores, $expert_item_scores, $is_readonly, $is_chairman_view;
    $items = json_decode($json_data, true);
    $count = is_array($items) ? count($items) : 0;
    $item_id = "startups_" . $item_number;
    $readonly_mode = isset($is_readonly) && $is_readonly || isset($is_chairman_view) && $is_chairman_view;
    
    // Get saved expert score for this item from JSON (if stored)
    $saved_expert_score = 0;
    if (isset($expert_item_scores) && is_array($expert_item_scores) && isset($expert_item_scores[$item_id])) {
        $saved_expert_score = (float)$expert_item_scores[$item_id];
    }
    
    // Initial input value: Use saved score if available, otherwise 0
    $initial_input_value = $saved_expert_score;
    
    // Helper function to get entry display name/value
    $getEntryDisplay = function($item) {
        // Try common name/title fields (including Forbes Alumni specific fields)
        $name = $item['name'] ?? $item['title'] ?? $item['startup_name'] ?? $item['program_name'] ?? $item['founder_company_name'] ?? '';
        
        // Filter out "-" values
        $name = trim($name);
        if ($name === '-') {
            $name = '';
        }
        
        // If no name field, use first non-empty value (excluding "-")
        if (empty($name)) {
            foreach ($item as $key => $val) {
                $clean_val = trim((string)$val);
                if (!empty($clean_val) && $clean_val !== '-' && !in_array(strtolower($key), ['id', 'dept_id', 'a_year', 'serial_number', 'type'])) {
                    $name = $clean_val;
                    break;
                }
            }
        }
        
        // Build value string from other fields
        $value_parts = [];
        foreach ($item as $key => $val) {
            // Filter out "-", empty values, and system fields
            $clean_val = trim((string)$val);
            if (!empty($clean_val) && $clean_val !== '-' && !in_array(strtolower($key), ['id', 'dept_id', 'a_year', 'serial_number', 'name', 'title', 'startup_name', 'program_name', 'founder_company_name', 'type'])) {
                $key_label = ucfirst(str_replace('_', ' ', $key));
                $value_parts[] = "$key_label: " . htmlspecialchars($clean_val);
            }
        }
        $value_str = !empty($value_parts) ? implode(', ', $value_parts) : '';
        
        return ['name' => htmlspecialchars($name), 'value' => $value_str, 'type' => htmlspecialchars($item['type'] ?? '')];
    };
    
    // Helper function to render document links
    $renderDocLinks = function($documents) {
        if (empty($documents)) {
            return '<small class="text-muted">No file chosen</small>';
        }
        
        $html = '<div class="d-flex flex-wrap gap-2">';
        foreach ($documents as $doc) {
            $doc_path = $doc['file_path'] ?? '';
            if (strpos($doc_path, '../') === 0) {
                $web_path = $doc_path;
            } elseif (strpos($doc_path, 'uploads/') === 0) {
                $web_path = '../' . $doc_path;
            } else {
                $web_path = '../' . ltrim($doc_path, '/');
            }
            
            $physical_path = dirname(__DIR__) . '/' . str_replace('../', '', $web_path);
            $file_exists = file_exists($physical_path);
            $doc_title = htmlspecialchars($doc['document_title'] ?? 'View Document');
            $doc_name = htmlspecialchars($doc['file_name'] ?? basename($doc_path));
            
            $html .= '<a href="' . htmlspecialchars($web_path) . '" ';
            $html .= 'target="_blank" ';
            $html .= 'class="btn btn-sm btn-outline-primary ' . ($file_exists ? '' : 'disabled') . '" ';
            $html .= 'title="' . $doc_title . '"';
            if (!$file_exists) {
                $html .= ' onclick="alert(\'Document file not found. Please contact administrator.\'); return false;"';
            }
            $html .= '>';
            $html .= '<i class="fas fa-file-pdf"></i> ';
            $html .= (strlen($doc_name) > 30 ? substr($doc_name, 0, 30) . '...' : $doc_name);
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    };
    ?>
    <div class="data-grid" <?php if ($readonly_mode): ?>style="grid-template-columns: 2fr 1fr 1fr 1fr;"<?php endif; ?> data-item="<?php echo $item_number; ?>">
        <div class="field-label">
            <strong><?php echo $item_number; ?>. <?php echo htmlspecialchars($label); ?></strong>
            
            <!-- All startup entries first (without individual documents) -->
            <?php if ($count > 0): ?>
                <div class="mt-2 mb-3">
                    <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_entries" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_entries">
                        <i class="fas fa-list"></i> View All Startup Entries (<?php echo $count; ?>)
                    </button>
                    <div class="collapse mt-2" id="<?php echo $item_id; ?>_entries">
                        <div class="card card-body bg-light">
                            <?php foreach ($items as $idx => $item): ?>
                                <?php
                                $entry_display = $getEntryDisplay($item);
                                $entry_name = $entry_display['name'];
                                $entry_value = $entry_display['value'];
                                $entry_type = $entry_display['type'];
                                ?>
                                <div class="mb-3 p-2 border rounded">
                                    <strong><?php echo ($idx + 1); ?>. <?php echo $entry_name ?: 'Entry ' . ($idx + 1); ?></strong>
                                    <?php if (!empty($entry_type)): ?>
                                        <span class="badge bg-secondary ms-2"><?php echo $entry_type; ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry_value)): ?>
                                        <div class="mt-1">
                                            <span class="text-muted"><?php echo $entry_value; ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- All supporting documents grouped by heading (single collapsible button) -->
            <div class="mt-2 mb-2">
                <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $item_id; ?>_docs" aria-expanded="false" aria-controls="<?php echo $item_id; ?>_docs">
                    <i class="fas fa-info-circle"></i> View Details
                </button>
                <div class="collapse mt-2" id="<?php echo $item_id; ?>_docs">
                    <div class="card card-body bg-light">
                        <?php foreach ($grouped_docs_array as $doc_heading => $documents): 
                            $doc_count = count($documents);
                        ?>
                            <div class="mb-3 p-2 border rounded">
                                <strong class="d-block mb-2"><?php echo htmlspecialchars($doc_heading); ?></strong>
                                <?php if ($doc_count > 0): ?>
                                    <div class="text-muted mb-2"><small>Document uploaded</small></div>
                                    <?php echo $renderDocLinks($documents); ?>
                                <?php else: ?>
                                    <small class="text-muted">No file chosen</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="dept-value">
            Count: <?php echo $count; ?>
        </div>
        
        <div class="auto-score" data-auto-score="<?php echo $auto_score; ?>">
            <?php echo number_format($auto_score, 2); ?> / <?php echo number_format($max_score, 2); ?>
        </div>
        
        <?php if (!$readonly_mode): ?>
        <div>
            <input type="number" 
                   step="0.01" 
                   min="0" 
                   max="<?php echo $max_score; ?>" 
                   class="expert-input" 
                   id="<?php echo $item_id; ?>" 
                   name="expert_item_<?php echo $item_number; ?>"
                   value="<?php echo number_format($initial_input_value, 2); ?>"
                   data-initial-value="<?php echo $initial_input_value; ?>"
                   data-auto-score="<?php echo $auto_score; ?>"
                   <?php echo $is_locked ? 'readonly' : ''; ?>
                   onchange="updateItemScore(<?php echo $item_number; ?>, this.value)">
        </div>
        <?php endif; ?>
        
        <div class="expert-score" id="<?php echo $item_id; ?>_score">
            <?php 
            // In readonly mode, show saved score if available, otherwise auto_score
            if ($readonly_mode) {
                $display_score = $saved_expert_score > 0 ? $saved_expert_score : $auto_score;
            } else {
                $display_score = $initial_input_value;
            }
            echo number_format($display_score, 2); 
            ?> / <?php echo number_format($max_score, 2); ?>
        </div>
    </div>
    
    <?php if (!$readonly_mode): ?>
    <script>
    // Update item score when input changes
    function updateItemScore(itemNum, value) {
        const input = document.getElementById('<?php echo $item_id; ?>');
        if (input) {
            const scoreDisplay = document.getElementById(input.id + '_score');
            if (scoreDisplay) {
                const maxScore = parseFloat(input.getAttribute('max')) || 0;
                const score = Math.min(Math.max(parseFloat(value) || 0, 0), maxScore);
                scoreDisplay.textContent = score.toFixed(2) + ' / ' + maxScore.toFixed(2);
            }
        }
        // Trigger section recalculation
        if (typeof recalculateScores === 'function') {
            recalculateScores();
        }
    }
    </script>
    <?php endif; ?>
    <?php
}
?>

