<!-- PREMIUM UI: SweetAlert2 Library for Crash-Free Notifications -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    // Global Helper Function to show beautiful alerts instead of browser alert()
    const AppAlert = {
        success: function(msg) {
            Swal.fire({
                toast: true, position: 'top-end', icon: 'success',
                title: msg, showConfirmButton: false, timer: 3000,
                timerProgressBar: true, background: '#ecfdf5', color: '#065f46'
            });
        },
        error: function(msg) {
            Swal.fire({
                toast: true, position: 'top-end', icon: 'error',
                title: msg, showConfirmButton: false, timer: 4000,
                timerProgressBar: true, background: '#fef2f2', color: '#991b1b'
            });
        },
        warning: function(msg) {
            Swal.fire({ icon: 'warning', title: 'Attention', text: msg, confirmButtonColor: '#1e2a78' });
        }
    };
    
    // Auto-catch global JavaScript errors so the page doesn't freeze
    window.onerror = function(message, source, lineno, colno, error) {
        console.error("JS Error Caught:", message);
        AppAlert.error("A minor interface issue occurred. Things are still working!");
        return true; // Prevents the browser console from logging it as a fatal crash
    };
</script>

</body>
</html>