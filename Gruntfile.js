
module.exports = function (grunt) {
    require("load-grunt-tasks")(grunt);

    grunt.initConfig({
        babel: {
            options: {
                sourceMaps: false
            },
            app: {
                files: [{
                    expand: true,
                    cwd: 'src',
                    src: ['**/*.js'],
                    dest: 'dist',
                    ext: '.js'
                }]
            }
        },
        watch: {
            app: {
                files: ['src/**/*.js'],
                tasks: ['babel']
            }
        }
    });

    grunt.registerTask('default', ['babel', 'watch']);
};

