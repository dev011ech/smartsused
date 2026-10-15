import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import 'core/config/app_config.dart';
import 'core/network/api_client.dart';
import 'features/auth/application/auth_controller.dart';
import 'features/auth/data/auth_service.dart';
import 'features/modules/application/module_controller.dart';
import 'features/modules/data/module_service.dart';
import 'navigation/app_router.dart';

class SmartSusedApp extends StatefulWidget {
  const SmartSusedApp({super.key});

  @override
  State<SmartSusedApp> createState() => _SmartSusedAppState();
}

class _SmartSusedAppState extends State<SmartSusedApp> {
  late final AuthController _authController;
  late final ModuleController _moduleController;
  late final ApiClient _apiClient;
  late Future<void> _initialization;

  @override
  void initState() {
    super.initState();
    _apiClient = ApiClient(AppConfig.apiBaseUrl);
    _authController = AuthController(AuthService(_apiClient));
    _moduleController = ModuleController(ModuleService(_apiClient));
    _initialization = _initialize();
  }

  Future<void> _initialize() async {
    await Future.wait([
      _authController.restoreSession(),
      _moduleController.load(),
    ]);
  }

  void _retryInitialization() {
    setState(() => _initialization = _initialize());
  }

  @override
  void dispose() {
    _authController.dispose();
    _moduleController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider.value(value: _apiClient),
        ChangeNotifierProvider.value(value: _authController),
        ChangeNotifierProvider.value(value: _moduleController),
      ],
      child: FutureBuilder<void>(
        future: _initialization,
        builder: (context, snapshot) {
          if (snapshot.hasError) {
            return MaterialApp(
              title: 'SmartSused',
              theme: _theme,
              home: _InitializationError(
                message: snapshot.error.toString(),
                onRetry: _retryInitialization,
              ),
            );
          }
          if (snapshot.connectionState != ConnectionState.done) {
            return MaterialApp(
              title: 'SmartSused',
              theme: _theme,
              home: const _InitializationLoading(),
            );
          }

          return const SmartSusedRouter();
        },
      ),
    );
  }

  static final _theme = ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: const Color(0xFF172554),
      brightness: Brightness.light,
    ),
    scaffoldBackgroundColor: const Color(0xFFF5F7FA),
    appBarTheme: const AppBarTheme(
      backgroundColor: Colors.white,
      foregroundColor: Color(0xFF172554),
      surfaceTintColor: Colors.transparent,
    ),
    cardTheme: const CardThemeData(
      color: Colors.white,
      surfaceTintColor: Colors.transparent,
      elevation: 1,
      margin: EdgeInsets.zero,
    ),
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
    ),
  );
}

class _InitializationLoading extends StatelessWidget {
  const _InitializationLoading();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Pripravujeme aplikáciu…'),
          ],
        ),
      ),
    );
  }
}

class _InitializationError extends StatelessWidget {
  const _InitializationError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(LucideIcons.cloudOff, size: 48),
              const SizedBox(height: 16),
              const Text(
                'Aplikáciu sa nepodarilo spustiť.',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 8),
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: onRetry,
                child: const Text('Skúsiť znova'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
