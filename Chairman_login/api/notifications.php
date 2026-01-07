<?php
/**
 * Chairman Notifications Page - All Remarks & Expert Responses
 * Dedicated page for viewing all communication history
 */

require('session.php');
require('functions.php');

$academic_year = getAcademicYear();

// Get all remarks sent by chairman (across all departments)
$query = "SELECT cr.*, 
          dm.DEPT_COLL_NO AS DEPT_CODE,
          COALESCE(dn.collname, dm.DEPT_NAME) AS DEPT_NAME
          FROM chairman_remarks cr
          INNER JOIN department_master dm ON cr.dept_id = dm.DEPT_ID
          LEFT JOIN departmentnames dn ON dn.collno = dm.DEPT_COLL_NO
          WHERE cr.academic_year = ?
          ORDER BY cr.created_at DESC";
$stmt = $conn->prepare($query);
$all_remarks = [];
if ($stmt) {
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_remarks[] = $row;
    }
    mysqli_free_result($result);
    $stmt->close();
}

// Count statistics
$total_remarks = count($all_remarks);
$with_responses = count(array_filter($all_remarks, function($r) { return !empty($r['expert_response']); }));
$pending_responses = $total_remarks - $with_responses;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Chairman Console</title>
    <link rel="icon" type="image/png" href="../assets/img/mumbai-university-removebg-preview.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #1e3a8a;
            --accent-green: #10b981;
            --accent-amber: #f59e0b;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        
        .top-bar {
            background: var(--primary-blue);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
            margin: 2rem auto;
            padding: 0 2rem;
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
        
        .notifications-container {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .notification-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.2s;
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
        
        .notification-actions {
            display: flex;
            gap: 0.5rem;
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
                    <i class="fas fa-bell"></i> Notifications & Communication History
                </h1>
                <small>Academic Year: <?php echo htmlspecialchars($academic_year); ?></small>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-light btn-sm me-2">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <a href="../logout.php" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>
    
    <div class="container-main">
        <!-- Statistics -->
        <div class="stats-cards">
            <div class="stat-card">
                <i class="fas fa-comments fa-2x text-primary mb-2"></i>
                <h3><?php echo $total_remarks; ?></h3>
                <p>Total Remarks</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                <h3><?php echo $with_responses; ?></h3>
                <p>With Responses</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-hourglass-half fa-2x text-warning mb-2"></i>
                <h3><?php echo $pending_responses; ?></h3>
                <p>Pending Responses</p>
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
                        <option value="acknowledged">Acknowledged</option>
                        <option value="resolved">Resolved</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><strong>Response:</strong></label>
                    <select id="filterResponse" class="form-select">
                        <option value="">All</option>
                        <option value="with">With Response</option>
                        <option value="without">Without Response</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Notifications List -->
        <div class="notifications-container">
            <h3 class="mb-4">
                <i class="fas fa-list"></i> All Remarks (<?php echo $total_remarks; ?>)
            </h3>
            
            <?php if (empty($all_remarks)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No remarks sent yet.</p>
                </div>
            <?php else: ?>
                <div id="notificationsList">
                    <?php foreach ($all_remarks as $remark): ?>
                        <div class="notification-item" 
                             data-priority="<?php echo htmlspecialchars($remark['priority']); ?>"
                             data-status="<?php echo htmlspecialchars($remark['status']); ?>"
                             data-has-response="<?php echo !empty($remark['expert_response']) ? 'with' : 'without'; ?>"
                             data-dept="<?php echo strtolower(htmlspecialchars($remark['DEPT_NAME'])); ?>"
                             data-remark="<?php echo strtolower(htmlspecialchars($remark['remark_text'])); ?>"
                             data-response="<?php echo !empty($remark['expert_response']) ? strtolower(htmlspecialchars($remark['expert_response'])) : ''; ?>">
                            <div class="notification-header">
                                <div style="flex: 1;">
                                    <div class="notification-dept">
                                        <i class="fas fa-building text-primary"></i>
                                        <?php echo htmlspecialchars($remark['DEPT_NAME']); ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($remark['DEPT_CODE']); ?>)</small>
                                    </div>
                                    <div class="notification-meta">
                                        <span><i class="fas fa-clock"></i> <?php echo date('d M Y, h:i A', strtotime($remark['created_at'])); ?></span>
                                        <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($remark['category']); ?></span>
                                        <span><i class="fas fa-tag"></i> <?php echo ucfirst(str_replace('_', ' ', $remark['remark_type'])); ?></span>
                                    </div>
                                </div>
                                <div class="notification-actions">
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
                                <strong>Your Remark:</strong>
                                <div style="background: #f9fafb; padding: 0.75rem; border-radius: 6px; margin-top: 0.5rem;">
                                    <?php echo nl2br(htmlspecialchars($remark['remark_text'])); ?>
                                </div>
                            </div>
                            
                            <?php if ($remark['expert_response']): ?>
                                <div class="expert-response-box">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                                        <strong style="color: #065f46;">
                                            <i class="fas fa-user-check"></i> Expert Response
                                        </strong>
                                        <small style="color: #059669;">
                                            <?php echo date('d M Y, h:i A', strtotime($remark['expert_response_at'])); ?>
                                        </small>
                                    </div>
                                    <div style="color: #047857; white-space: pre-wrap;">
                                        <?php echo nl2br(htmlspecialchars($remark['expert_response'])); ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 0.75rem; text-align: center; margin-top: 0.5rem;">
                                    <span style="color: #92400e;">
                                        <i class="fas fa-hourglass-half"></i> Awaiting expert response
                                    </span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-2">
                                <a href="department.php?dept_id=<?php echo $remark['dept_id']; ?>&cat_id=<?php echo $remark['category']; ?>&name=<?php echo urlencode($remark['category']); ?>" 
                                   class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> View Department
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter and search functionality
        document.getElementById('searchInput')?.addEventListener('input', filterNotifications);
        document.getElementById('filterPriority')?.addEventListener('change', filterNotifications);
        document.getElementById('filterStatus')?.addEventListener('change', filterNotifications);
        document.getElementById('filterResponse')?.addEventListener('change', filterNotifications);
        
        function filterNotifications() {
            const searchTerm = (document.getElementById('searchInput')?.value || '').toLowerCase();
            const priorityFilter = document.getElementById('filterPriority')?.value || '';
            const statusFilter = document.getElementById('filterStatus')?.value || '';
            const responseFilter = document.getElementById('filterResponse')?.value || '';
            
            const items = document.querySelectorAll('.notification-item');
            items.forEach(item => {
                const priority = item.getAttribute('data-priority');
                const status = item.getAttribute('data-status');
                const hasResponse = item.getAttribute('data-has-response');
                const dept = item.getAttribute('data-dept');
                const remark = item.getAttribute('data-remark');
                const response = item.getAttribute('data-response');
                
                const matchPriority = !priorityFilter || priority === priorityFilter;
                const matchStatus = !statusFilter || status === statusFilter;
                const matchResponse = !responseFilter || hasResponse === responseFilter;
                const matchSearch = !searchTerm || 
                    dept.includes(searchTerm) || 
                    remark.includes(searchTerm) || 
                    response.includes(searchTerm);
                
                item.style.display = (matchPriority && matchStatus && matchResponse && matchSearch) ? 'block' : 'none';
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
                        // Update stats
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
    </script>
</body>
</html>

