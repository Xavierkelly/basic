/*
* Template Name: Without Sidbar
* Description: Page template without sidebar
*/
<?php get_header(); ?>

	<div class="row">

		<div class="col-sm-12 blog-main">

			<?php
			if ( have_posts() ) : while ( have_posts() ) : the_post();

				get_template_part( 'content', get_post_format() );

			endwhile; endif;
			?>

		</div> <!-- /.blog-main -->

	</div> <!-- /.row -->

<?php get_footer(); ?>
