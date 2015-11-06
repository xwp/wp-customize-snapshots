/* jshint node:true */
module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {

		pkg: grunt.file.readJSON( 'package.json' ),

		// JavaScript linting with JSHint.
		jshint: {
			options: {
				jshintrc: '.jshintrc'
			},
			all: [
				'Gruntfile.js',
				'js/*.js',
				'!js/*.min.js'
			]
		},

		// Generate POT files.
		makepot: {
			target: {
				options: {
					potFilename: '<%= pkg.name %>.pot',
					exclude: [
						'build/.*' // Exclude build directory
					],
					processPot: function( pot ) {
						pot.headers['project-id-version'];
						return pot;
					},
					type: 'wp-plugin',
					domainPath: 'languages',
					potHeaders: {
						'report-msgid-bugs-to': 'XWP',
						'last-translator': 'XWP',
						'language-team': 'XWP'
					}
				}
			}
		},

		// Check textdomain errors.
		checktextdomain: {
			options:{
				text_domain: '<%= pkg.name %>',
				keywords: [
					'__:1,2d',
					'_e:1,2d',
					'_x:1,2c,3d',
					'esc_html__:1,2d',
					'esc_html_e:1,2d',
					'esc_html_x:1,2c,3d',
					'esc_attr__:1,2d',
					'esc_attr_e:1,2d',
					'esc_attr_x:1,2c,3d',
					'_ex:1,2c,3d',
					'_n:1,2,4d',
					'_nx:1,2,4c,5d',
					'_n_noop:1,2,3d',
					'_nx_noop:1,2,3c,4d'
				]
			},
			files: {
				src:	[
					'**/*.php', // Include all files
					'!build/**', // Exclude build/
					'!node_modules/**', // Exclude node_modules/
					'!tests/**' // Exclude tests/
				],
				expand: true
			}
		},

		// Build a deploy-able plugin
		copy: {
			build: {
				src: [
					'**',
					'!.*',
					'!.*/**',
					'!.DS_Store',
					'!build/**',
					'!composer.json',
					'!contributing.md',
					'!dev-lib/**',
					'!Gruntfile.js',
					'!node_modules/**',
					'!npm-debug.log',
					'!package.json',
					'!phpcs.ruleset.xml',
					'!phpunit.xml.dist',
					'!readme.md',
					'!tests/**'
				],
				dest: 'build/<%= pkg.name %>',
				expand: true,
				dot: true
			}
		},

		// Clean up the build
		clean: {
			build: {
				src: [ 'build' ]
			}
		},

		// Deploys a git Repo to the WordPress SVN repo
		wp_deploy: {
			deploy: {
				options: {
					plugin_slug: '<%= pkg.name %>',
					svn_user: 'westonruter',
					build_dir: 'build',
					assets_dir: 'wp-assets'
				}
			}
    }

	} );

	// Load tasks
	grunt.loadNpmTasks( 'grunt-checktextdomain' );
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-wp-deploy' );
	grunt.loadNpmTasks( 'grunt-wp-deploy' );
	grunt.loadNpmTasks( 'grunt-wp-i18n' );

	// Register tasks
	grunt.registerTask( 'default', [
		'jshint',
		'checktextdomain'
	] );

	grunt.registerTask( 'build', [
		'default',
		'makepot',
		'copy'
	] );

	grunt.registerTask( 'deploy', [
		'build',
		'wp_deploy',
		'clean'
	] );

};
