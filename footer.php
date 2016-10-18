  </div> <!-- /.container -->
</section> <!-- /#main -->

<footer class="blog-footer">
  <div id="footer-sidebar" class="container">
    <div id="footer-sidebar1" class="col-xs-12 col-sm-4 col-md-4">
    <?php
    if(is_active_sidebar('footer-sidebar-1')){
    dynamic_sidebar('footer-sidebar-1');
    }
    ?>
    </div>
    <div id="footer-sidebar2" class="col-xs-12 col-sm- col-md-4">
    <?php
    if(is_active_sidebar('footer-sidebar-2')){
    dynamic_sidebar('footer-sidebar-2');
    }
    ?>
    </div>
    <div id="footer-sidebar3" class="col-xs-12 col-sm-4 col-md-4">
    <?php
    if(is_active_sidebar('footer-sidebar-3')){
    dynamic_sidebar('footer-sidebar-3');
    }
    ?>
    </div>
  </div>
  <div class="copy">
    <span><?php echo get_bloginfo('name'); ?> &copy; <?php echo date("Y") ?></span>
  </div>
</footer>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js"></script>
  <script src="https://use.fontawesome.com/59a0bca051.js"></script>
  <?php wp_footer(); ?>
</body>
</html>
