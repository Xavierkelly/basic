<div class="blog-header-image">
	<?php if ( has_post_thumbnail() ) {
		the_post_thumbnail();
	} ?>
</div>
<div class="row">
	<div class="social-component">
		<?php echo '<a href="https://www.facebook.com/sharer/sharer.php?u='; echo get_permalink(); echo'" target= _blank class="facebook share col-xs-4 col-sm-2"><i class="fa fa-facebook"></i></a>
		<a href="http://twitter.com/share?&url='; echo get_permalink(); echo'" target= _blank class="twitter share col-xs-4 col-sm-2"><i class="fa fa-twitter"></i></a>
		<a href="https://plus.google.com/share?url='; echo get_permalink(); echo'" target= _blank class="google share col-xs-4 col-sm-2"><i class="fa fa-google-plus"></i></a>
		<a href="http://www.linkedin.com/shareArticle?url='; echo get_permalink(); echo'" target= _blank class="linkedin share col-xs-4 col-sm-2"><i class="fa fa-linkedin"></i></a>
		<a href="mailto:enteryour@addresshere.com?subject=Share and Follow&body='; echo get_permalink(); echo'" target= _blank class="email share col-xs-4 col-sm-2"><i class="fa fa-envelope"></i></a>
		<a href="//pinterest.com/pin/create/button/?url='; echo get_permalink(); echo'" target= _blank class="pinterest share col-xs-4 col-sm-2"> <i class="fa fa-pinterest"></i></a>
	</div>
	</div>'
		;?>
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