plugins {
    // Google services plugin — parses `android/app/google-services.json`
    // at build time and exposes its OAuth client entries (notably the
    // `client_type: 3` web client) to `google_sign_in_android` via
    // generated Android resources. Required for the v7.x native SDK on
    // Android — without this, Dart-side `GoogleSignIn.instance.initialize`
    // throws "serverClientId must be provided on Android".
    //
    // See: https://pub.dev/packages/google_sign_in_android#integration
    id("com.google.gms.google-services") version "4.5.0" apply false
}

allprojects {
    repositories {
        google()
        mavenCentral()
    }
}

val newBuildDir: Directory =
    rootProject.layout.buildDirectory
        .dir("../../build")
        .get()
rootProject.layout.buildDirectory.value(newBuildDir)

subprojects {
    val newSubprojectBuildDir: Directory = newBuildDir.dir(project.name)
    project.layout.buildDirectory.value(newSubprojectBuildDir)
}
// Force JVM target 21 across plugin subprojects (e.g. photo_manager,
// audioplayers_android) whose own android {} blocks pin VERSION_1_8 /
// jvmTarget='1.8'. Registered BEFORE evaluationDependsOn(":app") so the
// afterEvaluate listener fires AFTER each subproject's own config block
// runs — overriding their VERSION_1_8 instead of being overridden by it.
subprojects {
    afterEvaluate {
        if (plugins.hasPlugin("com.android.library")) {
            extensions.configure(com.android.build.gradle.LibraryExtension::class.java) {
                compileOptions {
                    sourceCompatibility = JavaVersion.VERSION_21
                    targetCompatibility = JavaVersion.VERSION_21
                }
            }
        }
        if (plugins.hasPlugin("com.android.application")) {
            extensions.configure(com.android.build.gradle.AppExtension::class.java) {
                compileOptions {
                    sourceCompatibility = JavaVersion.VERSION_21
                    targetCompatibility = JavaVersion.VERSION_21
                }
            }
        }
        if (plugins.hasPlugin("org.jetbrains.kotlin.android")) {
            tasks.withType(org.jetbrains.kotlin.gradle.tasks.KotlinCompile::class.java).configureEach {
                compilerOptions {
                    jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_21)
                }
            }
        }
    }
}
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
