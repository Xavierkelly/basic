<?php get_header(); ?>

<div class="row">
	<div class="col-sm-12">
		<div class="blog-header-image">
			<?php if ( has_post_thumbnail() ) { the_post_thumbnail(); } ?>
		</div><!-- /.blog-header-image -->
		<div class="social-component">
			<?php echo '<a href="https://www.facebook.com/sharer/sharer.php?u='; echo get_permalink(); echo'" target= _blank class="facebook share col-xs-4 col-sm-2"><i class="fa fa-facebook"></i></a>
			<a href="http://twitter.com/share?&url='; echo get_permalink(); echo'" target= _blank class="twitter share col-xs-4 col-sm-2"><i class="fa fa-twitter"></i></a>
			<a href="https://plus.google.com/share?url='; echo get_permalink(); echo'" target= _blank class="google share col-xs-4 col-sm-2"><i class="fa fa-google-plus"></i></a>
			<a href="http://www.linkedin.com/shareArticle?url='; echo get_permalink(); echo'" target= _blank class="linkedin share col-xs-4 col-sm-2"><i class="fa fa-linkedin"></i></a>
			<a href="mailto:enteryour@addresshere.com?subject=Share and Follow&body='; echo get_permalink(); echo'" target= _blank class="email share col-xs-4 col-sm-2"><i class="fa fa-envelope"></i></a>
			<a href="//pinterest.com/pin/create/button/?url='; echo get_permalink(); echo'" target= _blank class="pinterest share col-xs-4 col-sm-2"> <i class="fa fa-pinterest"></i></a>';?>
		</div><!-- /.social-component -->
			<?php
			if ( have_posts() ) : while ( have_posts() ) : the_post();

			get_template_part( 'content', get_post_format() );

			endwhile; endif;
			?>
</div> <!-- /.col -->
</div> <!-- /.row -->

<?php get_footer(); ?>
