    <!-- Page Content Ends Here -->
    
    <!-- Footer -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-left">
                <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></p>
            </div>
            <div class="footer-right">
                <p>Powered Firmory Digital solutulions</p>
            </div>
        </div>
    </footer>
    </div> <!-- Close main-content -->
    </div> <!-- Close app-layout -->
    
    <!-- JavaScript -->
    <script src="<?php echo APP_URL; ?>/assets/js/main.js"></script>
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo APP_URL . $js; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
