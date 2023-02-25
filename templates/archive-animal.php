<?php

/**
 * Archive Name: Animal Post
 * Archive Post Type: animal
 *
 * The archive template for displaying animals (custom post type)
 *
 *
 */
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}
define('DONOTCACHEPAGE', true);


get_header();

?>
<div role="main page-content">

	<div class="adopt-header align-middle page-header container">
		<?php printf('<h1 class="my-5 fs-1 ">Adopt an Animal</h1>'); ?>

	</div> <!-- end adopt-header -->

	<div class="container">
		<div class="row my-5">
			<div class="col">
				<h3 class="my-3">Please view our adoptable pets below.</h3>
				<h4 class="my-3">To begin your adoption process, please click the image (or button) of the pet you’d like to adopt to view more info about that animal. You will be redirected to Shelterluv to complete your adoption.</h4>
			</div>
		</div>
		<div class="row my-3">
			<a id="archive-top">&nbsp;
			</a>
		</div>
		<?php if (PLUGIN_DEBUG) {
			printf('<h2 class="red_pet">Total Animals: %d.</h2>', $GLOBALS['wp_query']->post_count);
		} ?>
		<div class="row post-tags container my-3">
			<?php
			$terms = get_terms('adopt-animals');
			$count = count($terms);
			if ($count > 0) {
				echo '<ul class="list-group list-group-horizontal-sm">';
				foreach ($terms as $term) { ?>

					<a href="<?php echo get_term_link($term->term_id); ?>" class="list-group-item list-group-item-action"><?php echo $term->name . 's'; ?> </a>

			<?php }
				echo '</ul>';
			} ?>
		</div>
		<div class="row row-cols-4 row-cols-md-4 g-3 my-5">
			<?php
			if (!have_posts()) {
				printf('<h2 class="red_pet">No adoptable animals at this time.</h2>');
			} else {

				while (have_posts()) {
					the_post();
					$post_link = get_permalink();
					$animal_type = get_field('animal_type');
			?>
					<div class="col card text-center archive animal archive-animal mx-auto" style="width: 18rem;">

						<?php printf('<a href="%s">', esc_url($post_link));
						$attr = array(
							'class' => 'card-img-top img-fluid',
							'alt' => get_the_title(),
						);
						the_post_thumbnail('large', $attr);
						printf('</a>');
						?>
						<div class="card-body my-3">
							<?php printf('<h4 class="card-title">%s</h4>', get_the_title()); ?>
							<p class="card-text"><?php printf("%s %s %s", get_field('color'), get_field('sex'), get_field('animal_type')); ?></p>
							<?php printf("<p>Breed: %s </p>", get_field('breed')); ?>
							<?php printf("<p>Age: %s </p>", get_field('age')); ?>
							<?php printf('<a href="%s" class="adopt-btn text-white btn btn-large">More Info</a>', esc_url($post_link)); ?>
						</div>

					</div> <!-- end card div -->
			<?php
				} // while posts loop
			} // end if (have_posts())
			?>
		</div> <!-- end card group -->

	</div> <!-- end container -->
	<div class="container my-5">
		<div class="row">
			<div class="col mx-auto">
				<?php printf('<a href="#archive-top" style="background-color: #0F9EDA; padding: .38rem 1.75rem; border-radius: 1.25rem;"  class="text-white btn btn-large">Back to Top</a>'); ?>
			</div>
		</div>
	</div>
	<!-- POST TAGS --->
	<div class="post-tags container mt-5 my-5">
		<?php
		$terms = get_terms('adopt-animals');
		$count = count($terms);
		if ($count > 0) {
			echo '<ul class="list-group list-group-horizontal-sm">';
			foreach ($terms as $term) { ?>

				<a href="<?php echo get_term_link($term->term_id); ?>" class="list-group-item list-group-item-action"><?php echo $term->name . 's'; ?> </a>

		<?php }
			echo '</ul>';
		} ?>
	</div>
	<?php wp_link_pages(); ?>

	<?php
	global $wp_query;
	if ($wp_query->max_num_pages > 1) :
	?>
		<nav class="pagination" role="navigation">
			<?php /* Translators: HTML arrow */ ?>
			<div class="nav-previous"><?php next_posts_link(sprintf(__('%s older', 'hello-elementor'), '<span class="meta-nav">&larr;</span>')); ?></div>
			<?php /* Translators: HTML arrow */ ?>
			<div class="nav-next"><?php previous_posts_link(sprintf(__('newer %s', 'hello-elementor'), '<span class="meta-nav">&rarr;</span>')); ?></div>
		</nav>
	<?php endif; ?>
</div> <!-- end main content -->

<?php
get_footer();