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
subprojects {
    project.evaluationDependsOn(":app")
}

tasks.register<Delete>("clean") {
    delete(rootProject.layout.buildDirectory)
}
