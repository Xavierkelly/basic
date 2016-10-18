<div class="blog-post-panel">
	<div class="blog-post">
		<h1 class="blog-post-title"><?php the_title(); ?></h1>
		<p class="blog-post-meta"><?php the_date(); ?> by <a href="#"><?php the_author(); ?></a></p>
		<?php the_content(); ?>
	</div><!-- /.blog-post -->
</div><!-- /.blog-post-pane -->
<?php if ( comments_open() || get_comments_number() ) {?>
    <div class="blog-post-panel">
        <?php comments_template(); ?>
    </div><!-- /.blog-post-pane -->
<?php } ?>