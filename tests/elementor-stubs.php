<?php
/**
 * Just enough of Elementor for the widgets to be built and asked questions.
 *
 * Elementor's `Widget_Base` is a large class with a manager behind it; the
 * widgets here use a handful of its methods — registering controls, reading
 * settings, rendering — and this stands in for those. It records every
 * control registered, so a test can read the panel a widget would show.
 */

namespace Elementor;

class Controls_Manager {
	const TEXT     = 'text';
	const NUMBER   = 'number';
	const TEXTAREA = 'textarea';
	const SELECT   = 'select';
	const SWITCHER = 'switcher';
	const HEADING  = 'heading';
	const COLOR    = 'color';
	const MEDIA    = 'media';
	const URL      = 'url';
	const REPEATER = 'repeater';
}

class Repeater {
	/** @var array<string, array<string, mixed>> */
	public array $controls = array();

	public function add_control( string $id, array $args ): void {
		$this->controls[ $id ] = $args;
	}

	public function get_controls(): array {
		return $this->controls;
	}
}

abstract class Widget_Base {
	/** @var array<string, array<string, mixed>> */
	public array $controls = array();

	/** @var array<string, mixed> */
	public array $settings = array();

	private string $section = '';

	abstract public function get_name();

	public function start_controls_section( string $id, array $args = array() ): void {
		$this->section         = $id;
		$this->controls[ $id ] = $args + array( 'type' => 'section' );
	}

	public function end_controls_section(): void {
		$this->section = '';
	}

	public function add_control( string $id, array $args ): void {
		$this->controls[ $id ] = $args + array( 'section' => $this->section );
	}

	public function get_controls(): array {
		$this->controls = array();
		$this->register_controls();

		return $this->controls;
	}

	protected function register_controls(): void {}

	public function get_settings_for_display() {
		return $this->settings;
	}

	public function get_categories() {
		return array();
	}

	public function get_script_depends() {
		return array();
	}

	public function get_style_depends() {
		return array();
	}

	/** Elementor calls render() inside output buffering; this returns it. */
	public function render_to_string( array $settings ): string {
		$this->settings = $settings;
		ob_start();
		$this->render();

		return (string) ob_get_clean();
	}

	protected function render(): void {}
}

/** The managers the integration registers into. */
class Widgets_Manager {
	/** @var array<string, Widget_Base> */
	public array $registered = array();

	public function register( Widget_Base $widget ): void {
		$this->registered[ (string) $widget->get_name() ] = $widget;
	}
}

class Elements_Manager {
	/** @var array<string, array<string, mixed>> */
	public array $categories = array();

	public function add_category( string $name, array $properties ): void {
		$this->categories[ $name ] = $properties;
	}
}
