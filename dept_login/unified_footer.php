            </div> <!-- End content-wrapper -->
        </main> <!-- End main-content -->
    </div> <!-- End app-container -->
    <?php 
    // include '../chatbot_widget.php'; 
    ?>
    <!-- Footer -->
    <?php include '../footer_main.php';?>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set active navigation item
            const currentPage = window.location.pathname.split('/').pop();
            const pageMapping = {
                'dashboard.php': 'dashboard',
                'profile.php': 'profile',
                'DetailsOfDepartment.php': 'department-details',
                'Programmes_Offered.php': 'programmes',
                'ExecutiveDevelopment.php': 'executive-development',
                'IntakeActualStrength.php': 'student-intake',
                'PlacementDetails.php': 'placement',
                'SalaryDetails.php': 'salary',
                'EmployerDetails.php': 'employer',
                'phd.php': 'phd',
                'FacultyDetails.php': 'faculty-details',
                'AcademicPeers.php': 'academic-peers',
                'FacultyOutput.php': 'faculty-output',
                'NEPInitiatives.php': 'nep-initiatives',
                'Departmental_Governance.php': 'departmental-governance',
                'StudentSupport.php': 'student-support',
                'ConferencesWorkshops.php': 'conferences',
                'Collaborations.php': 'collaborations',
                'Consolidated_Score.php': 'consolidated-score'
            };
            
            const currentPageId = pageMapping[currentPage];
            if (currentPageId) {
                document.querySelectorAll('.nav-item').forEach(item => {
                    item.classList.remove('active');
                });
                
                const activeItem = document.querySelector(`[data-page="${currentPageId}"]`);
                if (activeItem) {
                    activeItem.classList.add('active');
                }
            }
            
            // Initialize dropdowns
            const dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            const dropdownList = dropdownElementList.map(function (dropdownToggleEl) {
                return new bootstrap.Dropdown(dropdownToggleEl);
            });
            
            // Mobile sidebar toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }
            
            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 992) {
                    if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                        sidebar.classList.remove('show');
                    }
                }
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('show');
                }
            });
        });
        
        // Form validation helper
        function validateForm(formId) {
            const form = document.getElementById(formId);
            if (!form) return false;
            
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    isValid = false;
                } else {
                    field.classList.remove('is-invalid');
                    field.classList.add('is-valid');
                }
            });
            
            return isValid;
        }
        
        // Show loading state
        function showLoading(element) {
            if (element) {
                element.classList.add('loading');
                element.disabled = true;
            }
        }
        
        // Hide loading state
        function hideLoading(element) {
            if (element) {
                element.classList.remove('loading');
                element.disabled = false;
            }
        }
        
        // Show alert
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const contentWrapper = document.querySelector('.content-wrapper');
            if (contentWrapper) {
                contentWrapper.insertBefore(alertDiv, contentWrapper.firstChild);
                
                // Auto-dismiss after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
        }
        
        // Confirm dialog
        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }
        
        // Format number with commas
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        // Validate email
        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }
        
        // Validate phone number
        function isValidPhone(phone) {
            const phoneRegex = /^[0-9]{10}$/;
            return phoneRegex.test(phone);
        }
        
        // Validate positive number
        function validatePositiveNumber(input) {
            let value = input.value;
            value = value.replace(/[^0-9.]/g, '');
            
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            
            const numValue = parseFloat(value);
            if (numValue < 0) {
                value = '0';
            }
            
            input.value = value;
        }
        
        // Validate year
        function validateYear(input) {
            let value = input.value;
            value = value.replace(/[^0-9]/g, '');
            
            if (value.length > 4) {
                value = value.substring(0, 4);
            }
            
            input.value = value;
            
            const currentYear = new Date().getFullYear();
            const year = parseInt(value);
            
            input.classList.remove('is-valid', 'is-invalid');
            
            if (value.length === 4) {
                if (year >= 1900 && year <= currentYear) {
                    input.classList.add('is-valid');
                } else {
                    input.classList.add('is-invalid');
                }
            } else if (value.length > 0) {
                input.classList.add('is-invalid');
            }
        }
        
        // PDF validation
        function validatePDF(input) {
            const file = input.files[0];
            if (file) {
                const fileSize = file.size / 1024 / 1024; // Convert to MB
                const fileType = file.type;
                const fileName = file.name;
                const maxSizeMB = 5;
                
                if (fileType !== 'application/pdf') {
                    showAlert(`Invalid file type. Please select a PDF file only. Selected file: ${fileName}`, 'danger');
                    input.value = '';
                    return false;
                }
                
                if (fileSize > maxSizeMB) {
                    showAlert(`File too large. File size: ${fileSize.toFixed(2)} MB. Maximum allowed: ${maxSizeMB} MB. Please compress the PDF or select a smaller file.`, 'danger');
                    input.value = '';
                    return false;
                }
            }
            return true;
        }
    </script>
    <?php 
    // CRITICAL: Do NOT close connection here - footer_main.php already handles it
    // Closing connection twice causes "Connection already closed" errors and 404s
    // The footer_main.php include above will handle connection cleanup
    ?>
</body>
</html>
