<?php

/**
 * Template Name: Animal Post
 * Template Post Type: animal
 *
 * The template for displaying single animal posts (custom post type)
 *
 *
 */
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

get_header();

while (have_posts()) : the_post();
?>

	<main role="main">
		<!-- POST TAGS --->
		<div class="post-tags container my-5">
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
			<div class="page-header">
				<?php the_title('<h1 class="entry-title single-animal-name fw-bold">', '</h1>'); ?>
		</div>
	
		<div class="page-content container">

		<!-- container for pet info -->
				<div class="row">
					<div class="col-md-6">
						<?php
						printf('<a href="#" data-bs-toggle="modal" data-bs-target="#exampleModal%s">%s</a>', get_the_id(), get_the_post_thumbnail($post, 'large'));
						?>
					</div>
					<!-- start PET DETAILS div -->
					<div class="col-md-6 single-animal-details d-flex align-items-center">
						<div class="container">
							<div class="row">
								<?php printf("<h5>%s %s %s</h5>", get_field('color'), get_field('sex'), get_field('animal_type')); ?>
							</div>
							<div class="row">
								<?php printf("<h5>Breed: %s </h5>", get_field('breed')); ?>
							</div>
							<div class="row">
								<?php printf("<h5>Age: %s </h5>", get_field('age')); ?>
							</div>
							<div class="row">
								<?php if (get_field('animal_size')) {
									printf("<h5>Size: %s </h5>", get_field('animal_size'));
								} else {
									echo '';
								} ?>
							</div>
							<!-- PET bio -->
							<?php if (get_field('bio')) : ?>

								<div class="row">
									<h5 class="my-2" style="font-weight: bold;"><?php echo get_field('animal_name') ?>'s Biography</h5>
								</div>
								<div class="row">
									<?php printf('<p>%s</p>', get_field('bio')); ?>
								</div>
							<?php endif; ?>

							<div class="row">
								<p style="font-weight: bold;">To begin your adoption process, please click the adopt button below. You will be redirected to Shelterluv to complete your adoption.</p>
							</div>
							<div class="row">
								<p>Adoption Fees vary based on pet.</p>
							</div>

							<!-- action buttons -->

							<div class="row my-3">
								<?php
								printf('<button type="button" class="text-white fw-bold btn btn-large" data-bs-toggle="modal" data-bs-target="#adoptInfoModal">Adopt %s</button>', get_field('animal_name'));
								?>
							</div>
							<div class="row my-3">
								<?php printf('<button type="button" class="text-white fw-bold btn btn-large" data-bs-toggle="modal" data-bs-target="#exampleModal%s">More Photos</button>', get_the_id()); ?>
							</div>
						</div>
					</div> <!-- end pet-details div -->
				</div>

			<!-- PET PHOTOS MODAL -->
			<div class="container">
				<!-- Slider main container -->
				<div class="row">
					<div class="col ">
						<?php
						// Modal
						printf('<div class=" modal fade" id="exampleModal%s" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">', get_the_id());
						?>
						<div class="modal-dialog">
							<div class="modal-content">
								<div class="modal-header">
									<h5 class="modal-title" id="exampleModalLabel">More Photos</h5>
									<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
								</div>
								<div class="modal-body">
									<div class="row">

										<?php
										$photos = get_post_meta(get_the_id(), 'photos');
										//print_r($photos);
										if ($photos) :
											foreach ($photos as $photo) :
										?>
												<div class="col-lg-4 col-md-12 my-1 my-lg-1">
													<img class="img-fluid" src="<?php echo $photo; ?>" alt="<?php echo $photo ?>" />
												</div>
											<?php
											endforeach;
										else :
											?>
											<div class="col-lg-4 col-md-12 mb-4 mb-lg-0">
												<h3 class="text-center fw-bold">No other images to display</h3>
											</div>
										<?php
										endif;
										?>

									</div>
								</div>
								<div class="modal-footer">
									<button type="button" class="btn btn-secondary" style="border: none;" data-bs-dismiss="modal">Close</button>
								</div>
							</div>
						</div> <!-- end modal-dialog -->
					</div>

				</div>
				<div class="col"></div>
				<div class="col"></div>
			</div><!-- end more photos button row -->

		</div> <!-- end PET PHOTOS MODAL -->

		<!-- ADOPT INFO MODAL -->
		<div class="container">
			<!-- Slider main container -->
			<div class="row">
				<div class="col ">
					<?php
					// Modal
					printf('<div class=" modal fade" id="adoptInfoModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">', 'adoptinfo');
					?>
					<div class="modal-dialog">
						<div class="modal-content p-3">
							<div class="modal-header">
								<h5 class="modal-title" id="exampleModalLabel">Important Adoption Info</h5>
								<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							</div>
							<div class="modal-body container">
								<div class="row">

									<p><strong>Our mission is to complete families through a thoughtful and thorough adoption process.</strong></p>
									<p>To ensure every animal is placed in a forever home, GHHS begins this process with a detailed adoption application. Each interested, potential adopter will fill out the application attached to the animal of interest. To be considered for adoption:</p>
									<ul>
										<li>Adopters must be at least 19 years old.</li>
										<li>If you rent, you must have permission from your landlord and pet fees must be paid in advance. </li>
										<li>If adopting into a family, we require all members of the family to be in agreement about adopting. For families that already include another dog, we typically require a meet and greet; this may be hosted on the GHHS property, or at the potential adopter&#39s home.</li>
										<li>Some animals may require a home inspection.</li>
										<li>Verification of current pet(s) vaccine/vet records. </li>
									</ul>
									<p><b>Meeting these guidelines is not a guarantee that your application will be accepted. GHHS reserves the right to adopt only to qualified homes based upon our shelter guidelines. Each adoption is considered on a first-come, first-qualified basis once the animal is available for adoption. Exceptions may be made for potential adopters.</b></p>
									<?php
									if (get_field('adoption_fee') == 0) {
										printf('<p>Adoption Fees range, $100 and up based on pet.</p>');
									} else {
										printf('<p>Adoption Fee for %s: $%0.2f</p>', get_field('animal_name'), get_field('adoption_fee'));
									}
									?>
									<p>The adoption fee covers: spay/neuter surgery (legally required), current vaccines and boosters, a microchip with a lifetime registration, along with flea & tick preventative and heartworm preventative until time of adoption. In addition, each animal will be sent home with a small bag of food to help transition them to their new diet.</p>
									<p>Safety note: All dogs must leave wearing a leash and collar, cats must leave in a carrier. You may bring these items with you or purchase them at GHHS. Dog leashes/collars are available for purchase, $2 and up. Cat carriers (cardboard) are available for $5.</p>
								</div>
							</div>
							<div class="modal-footer">
								<?php // printf('<a style="background-color: #0f9eda; box-shadow: rgba(0,0,0,0.5) 0px 0px 10px 0px; margin: 5px;  border-radius: 0.75rem;" class="text-white fw-bold btn" href="%s">Adopt %s</a>', get_field('adopt_link'), get_field('animal_name')); ?>
								<?php printf('<a class="adopt-button fw-bold btn" href="%s">Adopt %s</a>', get_field('adopt_link'), get_field('animal_name')); ?>
								<button type="button" class="btn" style="border: none;" data-bs-dismiss="modal">Close</button>
							</div>
						</div>
					</div> <!-- end modal-dialog -->
				</div>

			</div>
			<div class="col"></div>
			<div class="col"></div>
		</div><!-- end adopt info button row -->

		</div> <!-- end ADOPT INFO MODAL -->


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
		</div> <!-- end page-content -->

	</main>

<?php
endwhile;

get_footer();
