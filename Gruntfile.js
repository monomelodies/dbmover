
module.exports = function (grunt) {
    grunt.loadNpmTasks('grunt-babel');
    grunt.loadNpmTasks('grunt-contrib-watch');

    grunt.initConfig({
        babel: {
            options: {
                sourceMaps: false
            },
            app: {
                files: {
                    'index.js': 'src/dbmover.js'
                }
            }
        },
        watch: {
            app: {
                files: ['src/dbmover.js'],
                tasks: ['babel']
            }
        }
    });

    grunt.registerTask('default', ['babel', 'watch']);
};

