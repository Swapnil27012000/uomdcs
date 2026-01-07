<?php
/**
 * Expert Notifications Page - Chairman Remarks & Responses
 * Dedicated page for viewing all chairman remarks
 */

// Set timezone FIRST
if (!date_default_timezone_get() || date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

require('session.php');
require_once(__DIR__ . '/../Chairman_login/functions.php');

$academic_year = getAcademicYear();

// Get all remarks for this expert
$expert_remarks = getExpertRemarks($email);
$unread_count = getUnreadRemarksCount($email);

// Count statistics
$total_remarks = count($expert_remarks);
$unread_remarks = count(array_filter($expert_remarks, function($r) { return $r['is_read'] == 0; }));
$acknowledged_remarks = count(array_filter($expert_remarks, function($r) { return $r['status'] === 'acknowledged'; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chairman Remarks - Expert Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #667eea;
            --secondary-color: #764ba2;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .top-bar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .top-bar-content {
            max-width: 1600px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container-main {
            max-width: 1600px;
            margin: 0 auto;
            padding: 0 2rem 4rem;
        }
        
        .dashboard-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            padding: 2rem;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-blue);
            margin: 0.5rem 0;
        }
        
        .stat-card p {
            color: #6b7280;
            margin: 0;
            font-size: 0.875rem;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .notification-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
        }
        
        .notification-item.unread {
            border-left: 4px solid var(--accent-amber);
            background: linear-gradient(to right, #fff9e6 0%, white 5%);
        }
        
        .notification-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .notification-dept {
            font-weight: 700;
            color: var(--primary-blue);
            font-size: 1.1rem;
        }
        
        .notification-meta {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .expert-response-box {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .search-box {
            position: relative;
        }
        
        .search-box input {
            padding-left: 2.5rem;
        }
        
        .search-box i {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <div class="top-bar-content">
            <div>
                <h1 class="mb-0">
                    <i class="fas fa-bullhorn"></i> Chairman Remarks & Notifications
                </h1>
                <small>Academic Year: <?php echo htmlspecialchars($academic_year); ?></small>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-primary btn-sm me-2">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="../logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
    
    <div class="container-main">
        <div class="dashboard-container">
            <!-- Statistics -->
            <div class="stats-cards">
                <div class="stat-card">
                    <i class="fas fa-comments fa-2x text-primary mb-2"></i>
                    <h3><?php echo $total_remarks; ?></h3>
                    <p>Total Remarks</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-bell fa-2x text-warning mb-2"></i>
                    <h3><?php echo $unread_remarks; ?></h3>
                    <p>Unread</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <h3><?php echo $acknowledged_remarks; ?></h3>
                    <p>Acknowledged</p>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><strong>Search:</strong></label>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by department, remark, or response...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><strong>Priority:</strong></label>
                        <select id="filterPriority" class="form-select">
                            <option value="">All Priorities</option>
                            <option value="urgent">Urgent</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><strong>Status:</strong></label>
                        <select id="filterStatus" class="form-select">
                            <option value="">All Status</option>
                            <option value="new">New</option>
                            <option value="read">Read</option>
                            <option value="acknowledged">Acknowledged</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><strong>Read Status:</strong></label>
                        <select id="filterRead" class="form-select">
                            <option value="">All</option>
                            <option value="unread">Unread</option>
                            <option value="read">Read</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Notifications List -->
            <h3 class="mb-4">
                <i class="fas fa-list"></i> All Remarks (<?php echo $total_remarks; ?>)
            </h3>
            
            <?php if (empty($expert_remarks)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No remarks from chairman yet.</p>
                </div>
            <?php else: ?>
                <div id="notificationsList">
                    <?php foreach ($expert_remarks as $remark): ?>
                        <div class="notification-item <?php echo $remark['is_read'] == 0 ? 'unread' : ''; ?>" 
                             data-priority="<?php echo htmlspecialchars($remark['priority']); ?>"
                             data-status="<?php echo htmlspecialchars($remark['status']); ?>"
                             data-is-read="<?php echo $remark['is_read'] == 0 ? 'unread' : 'read'; ?>"
                             data-dept="<?php echo strtolower(htmlspecialchars($remark['DEPT_NAME'])); ?>"
                             data-remark="<?php echo strtolower(htmlspecialchars($remark['remark_text'])); ?>"
                             data-response="<?php echo !empty($remark['expert_response']) ? strtolower(htmlspecialchars($remark['expert_response'])) : ''; ?>"
                             data-remark-id="<?php echo $remark['id']; ?>">
                            <div class="notification-header">
                                <div style="flex: 1;">
                                    <div class="notification-dept">
                                        <i class="fas fa-building text-primary"></i>
                                        <?php echo htmlspecialchars($remark['DEPT_NAME']); ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($remark['DEPT_CODE']); ?>)</small>
                                    </div>
                                    <div class="notification-meta">
                                        <span><i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($remark['created_at'])); ?></span>
                                        <span><i class="fas fa-tag"></i> <?php echo ucfirst(str_replace('_', ' ', $remark['remark_type'])); ?></span>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <span class="badge priority-<?php echo $remark['priority']; ?>" style="padding: 0.5rem 1rem;">
                                        <?php echo ucfirst($remark['priority']); ?>
                                    </span>
                                    <span class="badge bg-secondary" style="padding: 0.5rem 1rem;">
                                        <?php echo ucfirst($remark['status']); ?>
                                    </span>
                                    <button class="btn btn-sm btn-danger" onclick="deleteRemark(<?php echo $remark['id']; ?>, this)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-2">
                                <strong>Chairman's Remark:</strong>
                                <div style="background: #f9fafb; padding: 0.75rem; border-radius: 6px; margin-top: 0.5rem; border-left: 3px solid var(--accent-amber);">
                                    <?php echo nl2br(htmlspecialchars($remark['remark_text'])); ?>
                                </div>
                            </div>
                            
                            <?php if ($remark['expert_response']): ?>
                                <div class="expert-response-box">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <strong style="color: #065f46;">
                                            <i class="fas fa-reply"></i> Your Response
                                        </strong>
                                        <small style="color: #059669;">
                                            <?php echo date('d M Y, h:i A', strtotime($remark['expert_response_at'])); ?>
                                        </small>
                                    </div>
                                    <div style="color: #047857; white-space: pre-wrap;">
                                        <?php echo nl2br(htmlspecialchars($remark['expert_response'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-3 d-flex gap-2">
                                <?php if ($remark['is_read'] == 0): ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick="markAsRead(<?php echo $remark['id']; ?>, this)">
                                        <i class="fas fa-check"></i> Mark as Read
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($remark['status'] !== 'acknowledged' && $remark['status'] !== 'resolved'): ?>
                                    <button class="btn btn-sm btn-success" 
                                            onclick="openResponseModal(<?php echo $remark['id']; ?>, '<?php echo htmlspecialchars($remark['DEPT_NAME'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($remark['DEPT_CODE'], ENT_QUOTES); ?>', <?php echo htmlspecialchars(json_encode($remark['remark_text']), ENT_QUOTES); ?>)">
                                        <i class="fas fa-reply"></i> Respond
                                    </button>
                                <?php endif; ?>
                                
                                <a href="review_complete.php?dept_id=<?php echo $remark['dept_id']; ?>" 
                                   class="btn btn-sm btn-primary">
                                    <i class="fas fa-edit"></i> View Department
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Response Modal -->
    <div class="modal fade" id="responseModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-reply"></i> Respond to Chairman Remark
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="modalRemarkId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Department:</strong></label>
                        <p class="form-control-plaintext" id="modalDeptName"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Chairman's Remark:</strong></label>
                        <div class="alert alert-light" id="modalRemarkText"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Your Response (Optional):</strong></label>
                        <textarea id="modalResponse" class="form-control" rows="5" 
                                  placeholder="Enter your response to the chairman's remark..."></textarea>
                        <small class="text-muted">This will be sent to the chairman as acknowledgment.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitResponse()">
                        <i class="fas fa-check"></i> Acknowledge & Respond
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter and search functionality
        document.getElementById('searchInput')?.addEventListener('input', filterNotifications);
        document.getElementById('filterPriority')?.addEventListener('change', filterNotifications);
        document.getElementById('filterStatus')?.addEventListener('change', filterNotifications);
        document.getElementById('filterRead')?.addEventListener('change', filterNotifications);
        
        function filterNotifications() {
            const searchTerm = (document.getElementById('searchInput')?.value || '').toLowerCase();
            const priorityFilter = document.getElementById('filterPriority')?.value || '';
            const statusFilter = document.getElementById('filterStatus')?.value || '';
            const readFilter = document.getElementById('filterRead')?.value || '';
            
            const items = document.querySelectorAll('.notification-item');
            items.forEach(item => {
                const priority = item.getAttribute('data-priority');
                const status = item.getAttribute('data-status');
                const isRead = item.getAttribute('data-is-read');
                const dept = item.getAttribute('data-dept');
                const remark = item.getAttribute('data-remark');
                const response = item.getAttribute('data-response');
                
                const matchPriority = !priorityFilter || priority === priorityFilter;
                const matchStatus = !statusFilter || status === statusFilter;
                const matchRead = !readFilter || isRead === readFilter;
                const matchSearch = !searchTerm || 
                    dept.includes(searchTerm) || 
                    remark.includes(searchTerm) || 
                    response.includes(searchTerm);
                
                item.style.display = (matchPriority && matchStatus && matchRead && matchSearch) ? 'block' : 'none';
            });
        }
        
        // Mark as read
        function markAsRead(remarkId, button) {
            console.log('[markAsRead] Starting with remarkId:', remarkId);
            
            fetch('mark_remark_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'remark_id=' + remarkId
            })
            .then(async response => {
                console.log('[markAsRead] Response status:', response.status);
                console.log('[markAsRead] Response ok:', response.ok);
                
                // Get response text first
                const text = await response.text();
                console.log('[markAsRead] Response text:', text);
                
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status + ', body: ' + text);
                }
                
                const contentType = response.headers.get('content-type');
                console.log('[markAsRead] Content-Type:', contentType);
                
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('[markAsRead] Non-JSON response:', text);
                    return { success: response.ok };
                }
                
                if (!text || text.trim() === '') {
                    return { success: true };
                }
                
                try {
                    const data = JSON.parse(text);
                    console.log('[markAsRead] Parsed JSON:', data);
                    return data;
                } catch (e) {
                    console.error('[markAsRead] JSON parse error:', e);
                    throw new Error('Invalid JSON: ' + text.substring(0, 100));
                }
            })
            .then(data => {
                console.log('[markAsRead] Final data:', data);
                if (data && data.success) {
                    const item = button.closest('.notification-item');
                    item.classList.remove('unread');
                    button.remove();
                    console.log('[markAsRead] SUCCESS: Marked as read');
                } else {
                    console.error('[markAsRead] FAIL: data.success is false', data);
                    alert('Failed to mark as read: ' + (data && data.message ? data.message : 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('[markAsRead] ERROR:', error);
                alert('Failed to mark as read: ' + error.message);
            });
        }
        
        // Delete remark
        function deleteRemark(remarkId, button) {
            if (!confirm('Are you sure you want to delete this remark? This action cannot be undone.')) {
                return;
            }
            
            const item = button.closest('.notification-item');
            item.style.opacity = '0.5';
            button.disabled = true;
            
            fetch('api/delete_remark.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ remark_id: remarkId })
            })
            .then(async response => {
                if (!response.ok) {
                    const text = await response.text();
                    throw new Error('HTTP error! status: ' + response.status + (text ? ': ' + text.substring(0, 100) : ''));
                }
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    throw new Error('Invalid JSON response: ' + (text || 'Empty response').substring(0, 100));
                }
                const text = await response.text();
                if (!text || text.trim() === '') {
                    throw new Error('Empty response from server');
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON: ' + text.substring(0, 100));
                }
            })
            .then(data => {
                if (data && data.success) {
                    item.style.transition = 'opacity 0.3s';
                    item.style.opacity = '0';
                    setTimeout(() => {
                        item.remove();
                        location.reload();
                    }, 300);
                } else {
                    alert('Failed to delete remark: ' + (data?.message || 'Unknown error'));
                    item.style.opacity = '1';
                    button.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to delete remark: ' + (error.message || 'Please try again.'));
                item.style.opacity = '1';
                button.disabled = false;
            });
        }
        
        // Open response modal
        function openResponseModal(remarkId, deptName, deptCode, remarkText) {
            document.getElementById('modalRemarkId').value = remarkId;
            document.getElementById('modalDeptName').textContent = deptName + ' (' + deptCode + ')';
            document.getElementById('modalRemarkText').textContent = remarkText;
            document.getElementById('modalResponse').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('responseModal'));
            modal.show();
        }
        
        // Submit response
        function submitResponse() {
            const remarkId = document.getElementById('modalRemarkId').value;
            const response = document.getElementById('modalResponse').value.trim();
            
            if (!remarkId) {
                alert('Invalid remark ID');
                return;
            }
            
            const submitBtn = event.target;
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            
            fetch('acknowledge_remark.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    remark_id: remarkId,
                    response: response
                })
            })
            .then(async response => {
                if (!response.ok) {
                    const text = await response.text();
                    throw new Error('HTTP error! status: ' + response.status + (text ? ': ' + text.substring(0, 100) : ''));
                }
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    throw new Error('Invalid JSON response: ' + (text || 'Empty response').substring(0, 100));
                }
                const text = await response.text();
                if (!text || text.trim() === '') {
                    throw new Error('Empty response from server');
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON: ' + text.substring(0, 100));
                }
            })
            .then(data => {
                if (data && data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('responseModal'));
                    modal.hide();
                    location.reload();
                } else {
                    throw new Error(data?.message || 'Failed to acknowledge remark');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to send response: ' + (error.message || 'Please try again.'));
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        }
    </script>
</body>
</html>

