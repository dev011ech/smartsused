import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:provider/provider.dart';

import '../application/auth_controller.dart';
import '../../modules/application/module_controller.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    await _runLogin(() async {
      await context.read<AuthController>().login(
        _emailController.text,
        _passwordController.text,
      );
      return true;
    });
  }

  Future<void> _loginWithGoogle() async {
    await _runLogin(() => context.read<AuthController>().loginWithGoogle());
  }

  Future<void> _runLogin(Future<bool> Function() action) async {
    setState(() => _error = null);
    try {
      final isAuthenticated = await action();
      if (!isAuthenticated) return;
      if (mounted) {
        await context.read<ModuleController>().refresh();
        if (mounted) context.go('/dashboard');
      }
    } catch (error) {
      if (mounted) setState(() => _error = _messageFor(error));
    }
  }

  String _messageFor(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map<String, dynamic> && data['message'] is String) {
        return data['message'] as String;
      }
      return 'Nepodarilo sa spojiť so serverom. Skontrolujte internetové pripojenie.';
    }
    return error.toString().replaceFirst('Exception: ', '');
  }

  @override
  Widget build(BuildContext context) {
    final isBusy = context.watch<AuthController>().isBusy;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Form(
                    key: _formKey,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Icon(LucideIcons.building2, size: 48),
                        const SizedBox(height: 12),
                        Text(
                          'Vitajte v SmartSused',
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.headlineSmall,
                        ),
                        const SizedBox(height: 8),
                        const Text(
                          'Prihláste sa do svojho bytového domu.',
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: 24),
                        TextFormField(
                          controller: _emailController,
                          keyboardType: TextInputType.emailAddress,
                          autofillHints: const [AutofillHints.email],
                          decoration: const InputDecoration(
                            labelText: 'E-mail',
                          ),
                          validator: (value) =>
                              value != null && value.contains('@')
                              ? null
                              : 'Zadajte platnú e-mailovú adresu.',
                        ),
                        const SizedBox(height: 16),
                        TextFormField(
                          controller: _passwordController,
                          obscureText: true,
                          autofillHints: const [AutofillHints.password],
                          decoration: const InputDecoration(labelText: 'Heslo'),
                          validator: (value) =>
                              value != null && value.isNotEmpty
                              ? null
                              : 'Zadajte heslo.',
                        ),
                        if (_error != null) ...[
                          const SizedBox(height: 16),
                          Semantics(
                            liveRegion: true,
                            child: Text(
                              _error!,
                              style: TextStyle(
                                color: Theme.of(context).colorScheme.error,
                              ),
                            ),
                          ),
                        ],
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: isBusy ? null : _login,
                          child: Text(isBusy ? 'Prihlasujem…' : 'Prihlásiť sa'),
                        ),
                        const SizedBox(height: 12),
                        OutlinedButton.icon(
                          onPressed: isBusy ? null : _loginWithGoogle,
                          icon: const Icon(LucideIcons.globe, size: 22),
                          label: const Text('Pokračovať cez Google'),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
