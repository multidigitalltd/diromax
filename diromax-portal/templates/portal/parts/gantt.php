<?php
/**
 * Part: the timeline drawn as bars (spec 22), for the client and the staff.
 * The drawing is hidden from screen readers; the same facts are given as
 * text next to it (the client's list, the staff's table).
 *
 * @package Diromax\Portal
 *
 * @var array<string, mixed>             $layout          Geometry from Timeline::layout().
 * @var array<int, array<string, mixed>> $rows            Stages with their milestones.
 * @var string                           $dx_gantt_prefix Class prefix: dxp (portal) or dxa (staff).
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$dx_g     = $dx_gantt_prefix;
$dx_items = (array) $layout['items'];
$dx_style = static fn( ?array $span ): string => null === $span ? '' : sprintf( 'inset-inline-start:%s%%;inline-size:%s%%', $span['offset'], max( 0.6, (float) $span['width'] ) );
?>
<div class="<?php echo esc_attr( $dx_g . '-gantt' ); ?>" aria-hidden="true">
	<div class="<?php echo esc_attr( $dx_g . '-gantt__row ' . $dx_g . '-gantt__row--axis' ); ?>">
		<span class="<?php echo esc_attr( $dx_g . '-gantt__name' ); ?>"></span>
		<span class="<?php echo esc_attr( $dx_g . '-gantt__track' ); ?>">
			<?php foreach ( (array) $layout['months'] as $dx_month ) : ?>
				<span class="<?php echo esc_attr( $dx_g . '-gantt__month' ); ?>" style="<?php echo esc_attr( 'inset-inline-start:' . $dx_month['offset'] . '%' ); ?>"><?php echo esc_html( (string) wp_date( 'M y', (int) strtotime( $dx_month['month'] . '-01 12:00:00 UTC' ) ) ); ?></span>
			<?php endforeach; ?>
		</span>
	</div>
	<?php foreach ( $rows as $dx_row ) : ?>
		<?php $dx_place = $dx_items[ $dx_row['key'] ] ?? null; ?>
		<div class="<?php echo esc_attr( $dx_g . '-gantt__row ' . $dx_g . '-gantt__row--' . $dx_row['status'] ); ?>">
			<span class="<?php echo esc_attr( $dx_g . '-gantt__name' ); ?>"><?php echo esc_html( $dx_row['number'] . '. ' . $dx_row['label'] ); ?></span>
			<span class="<?php echo esc_attr( $dx_g . '-gantt__track' ); ?>">
				<i class="<?php echo esc_attr( $dx_g . '-gantt__today' ); ?>" style="<?php echo esc_attr( 'inset-inline-start:' . $layout['today'] . '%' ); ?>"></i>
				<?php if ( null !== $dx_place && null !== $dx_place['plan'] ) : ?>
					<i class="<?php echo esc_attr( $dx_g . '-gantt__plan' ); ?>" style="<?php echo esc_attr( $dx_style( $dx_place['plan'] ) ); ?>"></i>
				<?php elseif ( null !== $dx_place && null !== $dx_place['mark'] ) : ?>
					<?php // A planned end with no planned start: the end alone, as a tick. ?>
					<i class="<?php echo esc_attr( $dx_g . '-gantt__due' ); ?>" style="<?php echo esc_attr( 'inset-inline-start:' . $dx_place['mark'] . '%' ); ?>"></i>
				<?php endif; ?>
				<?php if ( null !== $dx_place && null !== $dx_place['actual'] ) : ?>
					<i class="<?php echo esc_attr( $dx_g . '-gantt__actual' ); ?>" style="<?php echo esc_attr( $dx_style( $dx_place['actual'] ) ); ?>"></i>
				<?php endif; ?>
				<?php foreach ( (array) $dx_row['milestones'] as $dx_milestone ) : ?>
					<?php $dx_mark = $dx_items[ $dx_milestone['key'] ]['mark'] ?? null; ?>
					<?php if ( null !== $dx_mark ) : ?>
						<i class="<?php echo esc_attr( $dx_g . '-gantt__mark' . ( $dx_milestone['done'] ? ' ' . $dx_g . '-gantt__mark--done' : '' ) ); ?>" style="<?php echo esc_attr( 'inset-inline-start:' . $dx_mark . '%' ); ?>" title="<?php echo esc_attr( $dx_milestone['label'] . ' · ' . $dx_milestone['end_label'] ); ?>"></i>
					<?php endif; ?>
				<?php endforeach; ?>
			</span>
		</div>
	<?php endforeach; ?>
	<p class="<?php echo esc_attr( $dx_g . '-gantt__legend' ); ?>">
		<span><i class="<?php echo esc_attr( $dx_g . '-gantt__plan' ); ?>"></i><?php esc_html_e( 'מתוכנן', 'diromax-portal' ); ?></span>
		<span><i class="<?php echo esc_attr( $dx_g . '-gantt__actual' ); ?>"></i><?php esc_html_e( 'בפועל', 'diromax-portal' ); ?></span>
		<span><i class="<?php echo esc_attr( $dx_g . '-gantt__mark' ); ?>"></i><?php esc_html_e( 'אבן דרך', 'diromax-portal' ); ?></span>
		<span><i class="<?php echo esc_attr( $dx_g . '-gantt__today' ); ?>"></i><?php esc_html_e( 'היום', 'diromax-portal' ); ?></span>
	</p>
</div>
